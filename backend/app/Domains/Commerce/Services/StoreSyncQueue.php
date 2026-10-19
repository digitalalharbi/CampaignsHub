<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use Illuminate\Support\Carbon;

/**
 * Queue one store read the way the store page's button does — a `queued` run row first, then the job.
 *
 * The row is what makes the card say «queued → running → success / failed» instead of nothing: the job
 * carries the run id and closes it. One place, because the sync button and a fresh assignment both need
 * the same two steps, and a second copy is how one of them ends up without the row.
 */
final class StoreSyncQueue
{
    /** The window a first read covers, in days. Products are read whole whatever the window. */
    public const BACKFILL_DAYS = 90;

    public function queue(ExternalAccount $store, int $days, string $source): IntegrationSyncRun
    {
        $from = Carbon::now()->subDays(max(1, min($days, 180)))->startOfDay();

        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $store->tenant_id,
            'provider_connection_id' => $store->provider_connection_id,
            'type' => 'commerce',
            'status' => 'queued',
            'started_at' => Carbon::now(),
        ])->save();

        SyncStoreJob::dispatch(
            (string) $store->getKey(),
            $from->toDateString(),
            Carbon::now()->endOfDay()->toDateString(),
            ['source' => $source, 'run_id' => (string) $run->getKey()],
        );

        return $run;
    }
}
