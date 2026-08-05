<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Console;

use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * COMMERCE-001 — the sweep that keeps a connected store current.
 *
 * ## The window, and why fourteen days
 *
 * An order changes for a fortnight after it is placed: paid, packed, shipped, delivered, returned,
 * refunded. Both providers restate it each time, and a sweep that only asked for today would leave
 * every order frozen at its most provisional state — a client's report showing pending orders that
 * were paid a week ago and revenue that was refunded. Fourteen days re-upserted idempotently is the
 * shortest window in which an order actually settles in this market.
 *
 * Hourly rather than half-hourly: a store's orders are not restated as often as an ad platform's
 * spend, and each pass is four paginated reads against APIs that throttle per app.
 *
 * Only stores behind a `connected` connection are swept — the same rule as the ad sweep, for the same
 * reason: attempting a revoked one writes a failure row every hour for ever.
 */
final class SyncStoresCommand extends Command
{
    protected $signature = 'commerce:sync
        {--days=14 : How many days back to re-ask for, so an order can settle}
        {--provider= : Limit the sweep to one platform}';

    protected $description = 'Queue a products/customers/orders/carts sync for every connected store.';

    public function handle(): int
    {
        $from = Carbon::now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $to = Carbon::now()->endOfDay();

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->whereIn('provider', ['salla', 'zid'])
            ->when($this->option('provider'), fn ($q, $provider) => $q->where('provider', $provider))
            ->pluck('id');

        if ($connections->isEmpty()) {
            $this->info('No connected store connections — nothing to sync.');

            return self::SUCCESS;
        }

        $queued = 0;

        ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', 'store')
            ->where('status', 'active')
            ->orderBy('id')
            ->chunkById(200, function ($stores) use ($from, $to, &$queued): void {
                foreach ($stores as $store) {
                    SyncStoreJob::dispatch(
                        (string) $store->id,
                        $from->toDateString(),
                        $to->toDateString(),
                        ['source' => 'scheduler'],
                    );
                    $queued++;
                }
            });

        $this->info("Queued {$queued} store sync(s) for {$from->toDateString()} → {$to->toDateString()}.");

        return self::SUCCESS;
    }
}
