<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * INTEG-RAW-001 — retention, so the audit trail does not become the database.
 *
 * Raw payloads are the biggest thing a sync writes and the least often read. Kept for ever they grow
 * without bound; deleted immediately they answer none of the questions they exist for. Ninety days is
 * the window in which somebody actually disputes a figure or notices a mapping bug.
 *
 * Deleted in batches rather than one statement, because a single delete over months of payloads takes
 * a lock long enough to be noticed by everything else using the table.
 */
final class PruneRawPayloadsCommand extends Command
{
    protected $signature = 'integrations:prune-raw {--days=90 : Keep payloads newer than this}';

    protected $description = 'Delete retained platform payloads older than the retention window.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(max(1, (int) $this->option('days')));
        $deleted = 0;

        do {
            $batch = IntegrationRawPayload::withoutGlobalScopes()
                ->where('fetched_at', '<', $cutoff)
                ->limit(1000)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += IntegrationRawPayload::withoutGlobalScopes()->whereIn('id', $batch)->delete();
        } while (true);

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * The payloads deleted — the only rows a prune touches.
         *
         * The number was already computed and already printed to a terminal nobody is watching at
         * 04:00, while `scheduled_runs.rows_affected` stayed null — so the ops page could say the run
         * SUCCEEDED without being able to say whether it did anything.
         */
        app(ScheduledRunRows::class)->report($deleted);

        $this->info("Pruned {$deleted} raw payload(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
