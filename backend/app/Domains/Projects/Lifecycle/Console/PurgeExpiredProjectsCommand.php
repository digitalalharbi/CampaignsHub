<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Projects\Lifecycle\InvalidLifecycleTransition;
use App\Domains\Projects\Lifecycle\LifecycleNotices;
use App\Domains\Projects\Lifecycle\ProjectPurger;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PROJECT-LIFECYCLE-001 §4 (builder N2) — `lifecycle:purge-expired [--apply]`, the trash's retention job.
 *
 * - DRY RUN BY DEFAULT (prints, writes nothing). The scheduler runs it with `--apply` once a day.
 * - Explicit condition only: status `deleted` + soft-deleted + not yet purged + `purge_after` set AND passed. Rows
 *   soft-deleted before the lifecycle have `purge_after` NULL and are never touched. A live, archived or draft project
 *   is never selected.
 * - 3 days before: one internal notice to the workspace owner(s) per project (`purge_warned_at` makes it once).
 * - Each purge goes through {@see ProjectPurger} (re-checks the refusal inside its own transaction; idempotent — a second
 *   run finds nothing). Keeps audit + notice logs; no provider API call. Logs every purge.
 */
final class PurgeExpiredProjectsCommand extends Command
{
    public const WARN_DAYS = 3;

    protected $signature = 'lifecycle:purge-expired
        {--apply : Purge and send the notices (default is a dry run)}
        {--dry-run : Explicit dry run (the default)}';

    protected $description = 'Permanently delete trashed projects whose retention has passed; warn owners 3 days before (dry run by default).';

    public function handle(ProjectPurger $purger, LifecycleNotices $notices): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $now = Carbon::now();
        $trash = fn () => Project::withoutGlobalScopes()->inLifecycle(ProjectStatus::Deleted)
            ->whereNotNull('deleted_at')->whereNull('purged_at')->whereNotNull('purge_after');

        // 1. The 3-day notice.
        $soon = $trash()->where('purge_after', '>', $now)->where('purge_after', '<=', $now->copy()->addDays(self::WARN_DAYS))
            ->whereNull('purge_warned_at')->orderBy('purge_after')->get();
        foreach ($soon as $project) {
            $this->line(($apply ? 'WARN ' : 'would warn ')."{$project->id} «{$project->name}» purge_after=".$project->purge_after?->toIso8601String());
            if ($apply) {
                $notices->purgeSoon($project);
                DB::table('projects')->where('id', $project->id)->where('tenant_id', $project->tenant_id)->update(['purge_warned_at' => $now]);
            }
        }

        // 2. The purge itself.
        $due = $trash()->where('purge_after', '<=', $now)->orderBy('purge_after')->get();
        $purged = 0;
        foreach ($due as $project) {
            try {
                $counts = $purger->purge($project, null, true, ! $apply);
            } catch (InvalidLifecycleTransition $e) {
                $this->warn("skip {$project->id}: {$e->getMessage()}");

                continue;
            }
            $purged += $apply ? 1 : 0;
            $this->line(($apply ? 'PURGED ' : 'would purge ')."{$project->id} «{$project->name}» rows=".array_sum($counts));
        }

        $summary = ['mode' => $apply ? 'apply' : 'dry-run', 'warned' => $soon->count(), 'due' => $due->count(), 'purged' => $purged];
        Log::info('lifecycle.purge_expired', $summary);
        app(ScheduledRunRows::class)->report($purged);
        $this->info(json_encode($summary, JSON_UNESCAPED_UNICODE) ?: '');

        return self::SUCCESS;
    }
}
