<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Metrics\Enums\SyncRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * A run that outlived the job that owns it is not running. It is dead, and the row is lying.
 *
 * ## What was actually found
 *
 * A forced Snapchat structure sync on 2026-09-07 refused to start, and its guard said why: a
 * structure run had been `running` since **2026-08-26**. Twelve days, on a job whose own timeout is
 * fifteen minutes.
 *
 * `SyncAccountStructureJob::failed()` already closes a run whose job the QUEUE knows died — a
 * timeout, an exception, a release to the failed table. It cannot close one whose worker vanished:
 * a SIGKILL, an OOM killer, a container replaced mid-flight. Nothing calls `failed()` then, and the
 * row stays open with no upper bound at all.
 *
 * ## Why that matters more than a stale row usually would
 *
 * The comment on that hook puts it exactly: «the run row is left open and the pipeline looks busy
 * rather than broken». An open run is read as work in progress by everything that looks — the
 * Integration Centre, the diagnosis, and the accept command, which refuses to queue a sweep while
 * one is outstanding. So a worker that died in August blocked an operator in September, and the
 * product's own answer to «what is happening» was wrong for twelve days.
 *
 * ## The rule
 *
 * A run may legitimately take as long as its job's `$timeout`. Twice that, plus a margin, cannot be
 * anything but a process that went away — so this closes it as failed and says so in the row, in the
 * same words the hook uses, because it is the same event arriving by a different route.
 *
 * It never touches a run that could still be alive: the cutoff is deliberately generous, and a job
 * still working is left alone to finish.
 */
final class CloseAbandonedSyncRunsCommand extends Command
{
    protected $signature = 'integrations:close-abandoned-runs {--minutes=60 : How old a running row must be before it counts as abandoned} {--apply : Close them; without this the command only reports}';

    protected $description = 'Close sync runs left «running» by a worker that went away';

    public function handle(): int
    {
        $minutes = max(30, (int) $this->option('minutes'));
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);

        $abandoned = IntegrationSyncRun::withoutGlobalScopes()
            ->where('status', SyncRunStatus::Running->value)
            ->where('started_at', '<', $cutoff)
            ->orderBy('started_at')
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info("No run has been open longer than {$minutes} minutes.");

            return self::SUCCESS;
        }

        $this->warn("{$abandoned->count()} run(s) have been «running» for more than {$minutes} minutes:");

        foreach ($abandoned as $run) {
            $this->line(sprintf(
                '  %s  %-9s started %s  connection %s',
                $run->getKey(),
                (string) $run->type,
                (string) $run->started_at,
                (string) $run->provider_connection_id,
            ));
        }

        if (! $this->option('apply')) {
            $this->line('');
            $this->info('Reporting only. Pass --apply to close them.');

            return self::SUCCESS;
        }

        /*
         * The same sentence `failed()` writes, because it is the same event: the process went away.
         * A different wording here would make one cause look like two in the log a person reads.
         */
        $closed = IntegrationSyncRun::withoutGlobalScopes()
            ->whereKey($abandoned->modelKeys())
            ->update([
                'status' => SyncRunStatus::Failed->value,
                'finished_at' => now(),
                'error' => 'The sync did not finish: the worker stopped it (timeout, memory, or a restart).',
            ]);

        $this->info("Closed {$closed} abandoned run(s).");

        return self::SUCCESS;
    }
}
