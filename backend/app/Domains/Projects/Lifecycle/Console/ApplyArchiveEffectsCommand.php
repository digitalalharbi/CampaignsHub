<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle\Console;

use App\Domains\Projects\Lifecycle\ArchiveEffects;
use App\Domains\Projects\Lifecycle\ProjectSnapshot;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §3/§9 — `lifecycle:apply-archive-effects --project=<id>|--all-archived [--apply]`.
 *
 * Applies the archive effects (stop syncs, schedules, rules, routines; close open alerts/tasks with «المشروع مؤرشف»;
 * expire pending recommendations) to projects that are ALREADY archived — e.g. archived before the effects existed.
 *
 * - DRY RUN BY DEFAULT: prints what would change and writes nothing. `--apply` writes.
 * - Explicit condition only: the project(s) must be in state `archived`; a live project is refused.
 * - Id-scoped: every statement is filtered by the project id and tenant (ArchiveEffects).
 * - Reversible: every change is recorded in project_archive_effects; un-archiving restores it.
 * - Idempotent: a second run changes nothing (only rows still «on» are touched).
 * - Prints a before/after snapshot per project (lifecycle:snapshot shape). NEVER deletes anything.
 */
final class ApplyArchiveEffectsCommand extends Command
{
    protected $signature = 'lifecycle:apply-archive-effects
        {--project= : One archived project id}
        {--all-archived : Every project currently in state archived (all workspaces)}
        {--apply : Write the changes (default is a dry run)}
        {--dry-run : Explicit dry run (the default)}';

    protected $description = 'Apply archive effects to archived projects (dry run by default; never deletes).';

    public function handle(ArchiveEffects $effects, ProjectSnapshot $snapshots): int
    {
        $one = trim((string) $this->option('project'));
        $all = (bool) $this->option('all-archived');
        if (($one === '') === ! $all) {
            $this->error('Give exactly one of --project=<id> or --all-archived.');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');

        $query = Project::withoutGlobalScopes()->inLifecycle(ProjectStatus::Archived)->orderBy('tenant_id')->orderBy('id');
        if ($one !== '') {
            $project = Project::withoutGlobalScopes()->find($one);
            if ($project === null) {
                $this->error('No project with that id.');

                return self::FAILURE;
            }
            if ($project->lifecycleStatus() !== ProjectStatus::Archived) {
                $this->error("Refused: the project is «{$project->status}», not archived. Nothing was changed.");

                return self::FAILURE;
            }
            $query->whereKey($one);
        }

        $this->line($apply ? 'MODE: APPLY' : 'MODE: DRY RUN (nothing is written; add --apply to write)');
        $report = [];
        foreach ($query->cursor() as $project) {
            /** @var Project $project */
            $before = $snapshots->for((string) $project->getKey()) ?? [];
            $summary = $apply
                ? DB::transaction(fn (): array => $effects->apply($project, null, false, null, false))
                : $effects->apply($project, null, false, null, true);
            $after = $apply ? ($snapshots->for((string) $project->getKey()) ?? []) : $before;

            $report[] = [
                'tenant_id' => (string) $project->tenant_id,
                'project_id' => (string) $project->getKey(),
                'effects' => array_filter($summary),
                'shared_accounts' => count($effects->sharedAccounts($project)),
                'changed' => ProjectSnapshot::diff($before, $after),
                'before' => $before,
                'after' => $after,
            ];
        }

        $this->line((string) json_encode(['apply' => $apply, 'projects' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info(count($report).' archived project(s) '.($apply ? 'processed' : 'inspected').'. Nothing was deleted.');

        return self::SUCCESS;
    }
}
