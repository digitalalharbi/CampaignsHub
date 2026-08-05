<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Actions\ImportStoreData;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Providers\ApiCommerceConnector;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * COMMERCE-001 — one store, one window, four reads: products, customers, orders, abandoned carts.
 *
 * ## The window is generous on purpose
 *
 * An order is not final when it is placed. It is paid minutes later, fulfilled days later, refunded
 * weeks later, and both providers restate it each time. A sweep that only asked for today would freeze
 * every order at its most provisional state — showing a paid order as pending and a refunded one as
 * revenue — so the window reaches back and the upsert is idempotent.
 *
 * ## Partial is a real outcome, and Zid makes it the normal one
 *
 * Zid publishes no abandoned-cart endpoint and its connector REFUSES rather than returning an empty
 * list. That refusal is recorded as a problem on the run and reads as `partial`, which is exactly
 * right: three of the four reads worked, and the funnel's checkout stage will have no cart data for
 * this store. An empty success would have quietly claimed a perfect checkout rate.
 */
final class StoreSyncer
{
    public function __construct(
        private readonly CommerceConnectorRegistry $registry,
        private readonly ImportStoreData $import,
    ) {}

    /** @param array<string,mixed> $meta */
    public function sync(ExternalAccount $store, Carbon $from, Carbon $to, array $meta = []): IntegrationSyncRun
    {
        $projectId = $this->projectIdFor($store);

        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $store->tenant_id,
            'project_id' => $projectId,
            'provider_connection_id' => $store->provider_connection_id,
            'type' => 'commerce',
            'status' => 'running',
            'started_at' => Carbon::now(),
        ])->save();

        $connector = $this->registry->get($store->provider);

        if ($connector === null) {
            return $this->finish($run, 'failed', 0, "No commerce connector is registered for '{$store->provider}'.");
        }

        if ($connector instanceof ApiCommerceConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($store->provider_connection_id);

            if ($connection === null) {
                return $this->finish($run, 'failed', 0, 'The store has no provider connection to sync through.');
            }

            $connector = $connector->withConnection($connection);
        }

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return $this->finish($run, 'awaiting_credentials', 0, 'No credentials for '.$connector->label().' — nothing was fetched.');
        }

        if ($projectId === null) {
            return $this->finish($run, 'failed', 0, 'This workspace has no project to file the store data under.');
        }

        $problems = [];
        $storeId = $store->external_id;
        $window = [$from->toDateString(), $to->toDateString()];

        /*
         * Products first, deliberately.
         *
         * An order line names a product by the platform's id, and the line can only be joined to a
         * product row that already exists. Ordering it the other way leaves every line unjoined until
         * the NEXT sweep, which is a day of a product report showing nothing.
         */
        $products = $this->attempt(fn () => $connector->syncProducts($storeId), $problems, 'products');
        $productCount = $products === null ? 0 : $this->import->products($store, $projectId, $products->records);

        $customers = $this->attempt(fn () => $connector->syncCustomers($storeId, ...$window), $problems, 'customers');
        $customerCount = $customers === null ? 0 : $this->import->customers($store, $projectId, $customers->records);

        $orders = $this->attempt(fn () => $connector->syncOrders($storeId, ...$window), $problems, 'orders');
        $orderCounts = $orders === null
            ? ['orders' => 0, 'items' => 0, 'attributed' => 0]
            : $this->import->orders($store, $projectId, $orders->records);

        $carts = $this->attempt(fn () => $connector->syncAbandonedCarts($storeId, ...$window), $problems, 'abandoned carts');
        $cartCount = $carts === null ? 0 : $this->import->abandonedCarts($store, $projectId, $carts->records);

        $records = $productCount + $customerCount + $orderCounts['orders'] + $cartCount;

        if ($connector instanceof ApiCommerceConnector) {
            $this->retainRaw($store, $run, $connector->takeRawResponses(), $from, $to, $records);
        }

        $store->forceFill(['last_synced_at' => Carbon::now()])->save();

        $status = match (true) {
            $records === 0 && $problems !== [] => 'failed',
            $problems !== [] => 'partial',
            default => 'success',
        };

        return $this->finish(
            $run,
            $status,
            $records,
            $problems === [] ? null : implode(' ', $problems),
        );
    }

    /**
     * Run one read, and let the other three carry on if it fails.
     *
     * @param  callable():SyncResult  $step
     * @param  list<string>  $problems
     */
    private function attempt(callable $step, array &$problems, string $what): ?SyncResult
    {
        try {
            $result = $step();
        } catch (Throwable $e) {
            $problems[] = ucfirst($what).': '.$e->getMessage();

            return null;
        }

        if (! $result->success) {
            $problems[] = ucfirst($what).': '.($result->message ?? 'the provider reported a failure.');

            return null;
        }

        return $result;
    }

    /**
     * Keep the store's own bodies (INTEG-RAW-001).
     *
     * More load-bearing here than for metrics: a dispute about a client's revenue is settled by the
     * order payload, and an order total that includes shipping on one provider and excludes it on the
     * other is only ever caught by reading what actually arrived.
     *
     * @param  list<array<string,mixed>>  $payloads
     */
    private function retainRaw(ExternalAccount $store, IntegrationSyncRun $run, array $payloads, Carbon $from, Carbon $to, int $rows): void
    {
        foreach ($payloads as $index => $payload) {
            IntegrationRawPayload::withoutGlobalScopes()->create([
                'tenant_id' => $store->tenant_id,
                'external_account_id' => $store->getKey(),
                'sync_run_id' => $run->getKey(),
                'provider' => $store->provider,
                'resource' => 'commerce',
                'window_start' => $from->toDateString(),
                'window_end' => $to->toDateString(),
                'payload' => $payload,
                'normalised_rows' => $index === 0 ? $rows : 0,
                'fetched_at' => Carbon::now(),
            ]);
        }
    }

    private function finish(IntegrationSyncRun $run, string $status, int $records, ?string $error): IntegrationSyncRun
    {
        $run->forceFill([
            'status' => $status,
            'records' => $records,
            'error' => $error === null ? null : mb_substr($error, 0, 250),
            'finished_at' => Carbon::now(),
        ])->save();

        return $run;
    }

    /**
     * Where a store's data is filed.
     *
     * The project its orders already live in, so a re-sync never re-files anything; otherwise the
     * workspace's own project. A store with nowhere to file is refused rather than filed arbitrarily.
     */
    private function projectIdFor(ExternalAccount $store): ?string
    {
        $existing = CommerceOrder::withoutGlobalScopes()
            ->where('external_account_id', $store->getKey())
            ->value('project_id');

        if ($existing !== null) {
            return $existing;
        }

        return Project::withoutGlobalScopes()
            ->where('tenant_id', $store->tenant_id)
            ->when($store->client_workspace_id, fn ($q, $ws) => $q->orderByRaw('CASE WHEN client_workspace_id = ? THEN 0 ELSE 1 END', [$ws]))
            ->orderBy('created_at')
            ->value('id');
    }
}
