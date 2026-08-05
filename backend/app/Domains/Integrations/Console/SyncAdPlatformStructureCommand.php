<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
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

    public function handle(): int
    {
        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->when($this->option('provider'), fn ($q, $provider) => $q->where('provider', $provider))
            ->pluck('id');

        if ($connections->isEmpty()) {
            $this->info('No connected provider connections — nothing to discover.');

            return self::SUCCESS;
        }

        $queued = 0;

        ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', 'ad_account')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use (&$queued): void {
                foreach ($accounts as $account) {
                    SyncAccountStructureJob::dispatch((string) $account->id, ['source' => 'scheduler']);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} structure sync(s).");

        return self::SUCCESS;
    }
}
