<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * INTEG-SYNC-001 — the sweep that makes synced data arrive without anybody pressing anything.
 *
 * Before this, every figure in the product depended on somebody opening the integrations page and
 * pressing sync. The pipeline was real; nothing drove it. A dashboard that is only current when
 * somebody remembers to refresh it is not a dashboard, it is a report with extra steps.
 *
 * ## What it sweeps, and what it deliberately leaves alone
 *
 * Only accounts whose CONNECTION is `connected`. An account behind a revoked, errored or
 * awaiting-credentials connection is skipped rather than attempted: attempting it would write a
 * failure row every half hour for ever, burying the one failure that means something under thousands
 * that mean "we already knew".
 *
 * ## The window is deliberately not just today
 *
 * Every platform restates recent days — conversions attribute late, spend is corrected, fraud is
 * refunded. A sweep that only ever asked for today would freeze each day's numbers at their most
 * wrong. Seven days back, re-upserted idempotently, is the shortest window that lets a figure settle.
 */
final class SyncAdPlatformsCommand extends Command
{
    protected $signature = 'integrations:sync
        {--days=7 : How many days back to re-ask for, so late attribution can settle}
        {--provider= : Limit the sweep to one platform}';

    protected $description = 'Queue a metrics sync for every ad account behind a connected provider.';

    public function handle(): int
    {
        $from = Carbon::now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $to = Carbon::now()->endOfDay();

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->when($this->option('provider'), fn ($q, $provider) => $q->where('provider', $provider))
            ->pluck('id');

        if ($connections->isEmpty()) {
            $this->info('No connected provider connections — nothing to sync.');

            return self::SUCCESS;
        }

        $queued = 0;

        ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', 'ad_account')
            ->where('status', 'active')
            ->orderBy('id')
            // Chunked because a platform-wide sweep on a busy install is thousands of accounts, and
            // loading them all to dispatch a job each is a memory profile that only fails in
            // production.
            ->chunkById(200, function ($accounts) use ($from, $to, &$queued): void {
                foreach ($accounts as $account) {
                    // The job is unique per (account, window), so a sweep that overlaps a manual sync
                    // or a webhook-driven one adds nothing rather than duplicating the work.
                    SyncAccountMetricsJob::dispatch(
                        (string) $account->id,
                        $from->toDateString(),
                        $to->toDateString(),
                        ['source' => 'scheduler'],
                    );
                    $queued++;
                }
            });

        $this->info("Queued {$queued} account sync(s) for {$from->toDateString()} → {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
