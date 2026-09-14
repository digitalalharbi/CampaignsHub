<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Services\StructureSweepTargets;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * STRUCT-001 — the sweep that discovers campaigns, ad sets, ads and creatives.
 *
 * ## Why it is separate from `integrations:sync`
 *
 * They answer different questions at different rates. Numbers are restated for a week after the fact,
 * so metrics re-ask for a seven-day window every half hour. Structure changes when a human changes it
 * — a few times a week — and each pass is four calls per account against APIs that count them. Running
 * both on the metrics cadence would multiply the platform call budget by four for information that
 * had not moved.
 *
 * It runs on the SIX-hour mark, before the metrics sweep on the same tick, because an insight for an
 * undiscovered campaign is dropped by `AccountMetricsSyncer` and counted as skipped. Discovering first
 * is what stops a brand-new campaign's first day of spend from being thrown away.
 *
 * Only accounts behind a `connected` connection are swept, for the same reason the metrics sweep skips
 * the rest: attempting a revoked connection writes a failure row every pass for ever, and buries the
 * one failure that means something.
 */
final class SyncAdPlatformStructureCommand extends Command
{
    protected $signature = 'integrations:sync-structure
        {--provider= : Limit the sweep to one platform}';

    protected $description = 'Queue a structure sync (campaigns, ad sets, ads, creatives) for every connected ad account.';

    public function handle(StructureSweepTargets $targets): int
    {
        $accounts = $targets->accounts($this->option('provider'));

        if ($accounts->isEmpty()) {
            $this->info('No connected, assigned ad accounts — nothing to discover.');

            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            SyncAccountStructureJob::dispatch((string) $account->id, ['source' => 'scheduler']);
        }

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Structure syncs queued, on the same rule as the metrics sync beside it.
         *
         * Already computed, already printed to a terminal nobody watches at 04:00, while
         * `scheduled_runs.rows_affected` stayed null — so the ops page could say SUCCEEDED without
         * being able to say whether the run did anything.
         */
        app(ScheduledRunRows::class)->report($accounts->count());

        $this->info("Queued {$accounts->count()} structure sync(s).");

        return self::SUCCESS;
    }
}
