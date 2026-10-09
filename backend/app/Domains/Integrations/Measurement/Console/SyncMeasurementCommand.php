<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement\Console;

use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Jobs\SyncMeasurementPropertyJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * GA4-INTEGRATION-001 — the sweep that keeps a SELECTED property current.
 *
 * ## Only selected properties, and only behind a live connection
 *
 * An agency's Google identity commonly reaches dozens of clients' properties. Sweeping what it can
 * see would read one client's site traffic because somebody at the agency happens to have access —
 * and would spend each of those clients' Data API quota to do it. `scopeToAssigned` is the same gate
 * the store and ad sweeps use, for the same reason.
 *
 * Properties behind a connection that is no longer `connected` are left alone: attempting a revoked
 * one writes a failure row every pass for ever.
 *
 * ## Four-hourly, not half-hourly
 *
 * GA4 is not restated as often as an ad platform's spend, and the Data API meters in TOKENS per
 * property per day rather than in requests. A half-hourly sweep would spend a client's whole daily
 * allowance on re-reading days that did not change, and the exhaustion lands on the client's own
 * Analytics usage too.
 */
final class SyncMeasurementCommand extends Command
{
    protected $signature = 'measurement:sync
        {--days= : How many days back to re-ask for; defaults to the restatement window}
        {--property= : Limit the sweep to one property id}';

    protected $description = 'Queue a GA4 sync for every property selected for a project.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? Ga4PropertySync::RESTATEMENT_DAYS);
        $days = max(1, min(365, $days));

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->where('provider', 'ga4')
            ->pluck('id');

        if ($connections->isEmpty()) {
            $this->info('No connected Analytics connections — nothing to sync.');
            app(ScheduledRunRows::class)->report(0);

            return self::SUCCESS;
        }

        $queued = 0;

        ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->where('status', 'active')
            ->whereNull('access_lost_at')
            ->when($this->option('property'), fn ($q, $property) => $q->where('external_id', $property))
            ->orderBy('id')
            ->tap(fn ($q) => app(AccountAssignment::class)->scopeToAssigned($q))
            ->chunkById(200, function ($properties) use ($days, &$queued): void {
                foreach ($properties as $property) {
                    SyncMeasurementPropertyJob::dispatch((string) $property->id, $days);
                    $queued++;
                }
            });

        /*
         * The syncs QUEUED, which is what this command does. The figures the jobs then write are the
         * jobs' own count, and claiming them here would count one pass's work twice.
         */
        app(ScheduledRunRows::class)->report($queued);

        $this->info("Queued {$queued} Analytics property sync(s) over the last {$days} day(s).");

        return self::SUCCESS;
    }
}
