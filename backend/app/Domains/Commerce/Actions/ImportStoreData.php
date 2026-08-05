<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Actions;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Services\OrderAttributionResolver;
use App\Domains\Commerce\ValueObjects\Attribution;
use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * COMMERCE-001 — the one place a store's answers become rows.
 *
 * Everything is upserted on `(external_account_id, external_id)` — the tables' own unique keys — so a
 * re-sync of an overlapping window updates in place. That matters more here than anywhere else in this
 * product: order syncs deliberately overlap, because an order placed at 23:58 and paid at 00:03 is
 * restated the next day, and a non-idempotent import would count a customer's purchase twice on a
 * client's revenue report.
 *
 * ## Nothing is deleted, and refunds are recorded rather than subtracted
 *
 * A cancelled order stays, with `cancelled_at` set. A refunded one stays, with the amount. Deleting
 * either would make last month's report change the next time it was opened — and the whole point of
 * `net_revenue` is that the gross figure and what the merchant kept are both visible.
 *
 * ## Attribution is resolved at import, and re-resolved on every import
 *
 * An order imported before its campaign was discovered would be permanently unattributed if the
 * resolution were a one-off. Re-running it costs one in-memory comparison per order and is what makes
 * the structure sweep and the order sweep converge instead of racing.
 */
final class ImportStoreData
{
    public function __construct(private readonly OrderAttributionResolver $attribution) {}

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return int products written
     */
    public function products(ExternalAccount $store, string $projectId, array $rows): int
    {
        $written = 0;

        DB::transaction(function () use ($store, $projectId, $rows, &$written): void {
            foreach ($rows as $row) {
                $externalId = (string) ($row['external_id'] ?? '');

                if ($externalId === '') {
                    continue;
                }

                CommerceProduct::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->getKey(), 'external_id' => $externalId],
                    [
                        'tenant_id' => $store->tenant_id,
                        'project_id' => $projectId,
                        'provider' => $store->provider,
                        'name' => (string) ($row['name'] ?? $externalId),
                        'sku' => $row['sku'] ?? null,
                        'status' => (string) ($row['status'] ?? 'active'),
                        'price' => $row['price'] ?? null,
                        'currency' => $row['currency'] ?? $store->currency,
                        'quantity' => $row['quantity'] ?? null,
                        'category' => $row['category'] ?? null,
                        'url' => $row['url'] ?? null,
                        'image_url' => $row['image_url'] ?? null,
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $written++;
            }
        });

        return $written;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return int customers written
     */
    public function customers(ExternalAccount $store, string $projectId, array $rows): int
    {
        $written = 0;

        DB::transaction(function () use ($store, $projectId, $rows, &$written): void {
            foreach ($rows as $row) {
                if ($this->customer($store, $projectId, $row) !== null) {
                    $written++;
                }
            }
        });

        return $written;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{orders:int,items:int,attributed:int}
     */
    public function orders(ExternalAccount $store, string $projectId, array $rows): array
    {
        // The project's discovered campaigns, loaded ONCE — attributing a thousand orders with a query
        // each is how a nightly sync becomes an outage.
        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->get(['id', 'external_id', 'name', 'provider', 'unified_campaign_id']);

        $counts = ['orders' => 0, 'items' => 0, 'attributed' => 0];

        DB::transaction(function () use ($store, $projectId, $rows, $campaigns, &$counts): void {
            foreach ($rows as $row) {
                $externalId = (string) ($row['external_id'] ?? '');

                if ($externalId === '') {
                    continue;
                }

                $customer = is_array($row['customer'] ?? null)
                    ? $this->customer($store, $projectId, $row['customer'])
                    : null;

                $attribution = $row['attribution'] instanceof Attribution
                    ? $row['attribution']
                    : Attribution::read();

                $order = CommerceOrder::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->getKey(), 'external_id' => $externalId],
                    [
                        'tenant_id' => $store->tenant_id,
                        'project_id' => $projectId,
                        'commerce_customer_id' => $customer?->getKey(),
                        'provider' => $store->provider,
                        'reference' => $row['reference'] ?? null,
                        'status' => (string) ($row['status'] ?? 'unknown'),
                        'payment_status' => $row['payment_status'] ?? null,
                        'placed_at' => $this->time($row['placed_at'] ?? null),
                        'currency' => $row['currency'] ?? $store->currency,
                        'subtotal' => $row['subtotal'] ?? null,
                        'shipping_total' => $row['shipping_total'] ?? null,
                        'tax_total' => $row['tax_total'] ?? null,
                        'discount_total' => $row['discount_total'] ?? null,
                        'total' => $row['total'] ?? null,
                        'refunded_total' => $row['refunded_total'] ?? null,
                        'refunded_at' => $this->time($row['refunded_at'] ?? null),
                        'cancelled_at' => $this->time($row['cancelled_at'] ?? null),
                        ...$attribution->toColumns(),
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $counts['items'] += $this->items($store, $order, $row['items'] ?? []);

                $this->attribution->apply($order, $campaigns);

                if ($order->external_campaign_id !== null) {
                    $counts['attributed']++;
                }

                $counts['orders']++;
            }
        });

        return $counts;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return int carts written
     */
    public function abandonedCarts(ExternalAccount $store, string $projectId, array $rows): int
    {
        $written = 0;

        DB::transaction(function () use ($store, $projectId, $rows, &$written): void {
            foreach ($rows as $row) {
                $externalId = (string) ($row['external_id'] ?? '');

                if ($externalId === '') {
                    continue;
                }

                $attribution = $row['attribution'] instanceof Attribution
                    ? $row['attribution']
                    : Attribution::read();

                CommerceAbandonedCart::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->getKey(), 'external_id' => $externalId],
                    [
                        'tenant_id' => $store->tenant_id,
                        'project_id' => $projectId,
                        'provider' => $store->provider,
                        'abandoned_at' => $this->time($row['abandoned_at'] ?? null),
                        'currency' => $row['currency'] ?? $store->currency,
                        'total' => $row['total'] ?? null,
                        'items_count' => $row['items_count'] ?? null,
                        'customer_email' => $row['customer_email'] ?? null,
                        'checkout_url' => $row['checkout_url'] ?? null,
                        ...$attribution->toColumns(),
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $written++;
            }
        });

        return $written;
    }

    /** @param array<string,mixed> $row */
    private function customer(ExternalAccount $store, string $projectId, array $row): ?CommerceCustomer
    {
        $externalId = (string) ($row['external_id'] ?? '');

        if ($externalId === '') {
            return null;
        }

        return CommerceCustomer::withoutGlobalScopes()->updateOrCreate(
            ['external_account_id' => $store->getKey(), 'external_id' => $externalId],
            array_filter([
                'tenant_id' => $store->tenant_id,
                'project_id' => $projectId,
                'provider' => $store->provider,
                'name' => $row['name'] ?? null,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'city' => $row['city'] ?? null,
                'country' => $row['country'] ?? null,
                'orders_count' => $row['orders_count'] ?? null,
                'total_spent' => $row['total_spent'] ?? null,
                'first_seen_at' => $this->time($row['first_seen_at'] ?? null),
                'is_demo' => false,
                'last_synced_at' => Carbon::now(),
                /*
                 * `array_filter` so a customer arriving as an order's SUMMARY — which carries a name
                 * and an email and nothing else — does not blank the richer row the customers sweep
                 * already wrote. A null from an abbreviated payload is «not stated here», not «empty».
                 */
            ], static fn ($v) => $v !== null),
        );
    }

    /**
     * @return int items written
     */
    private function items(ExternalAccount $store, CommerceOrder $order, mixed $items): int
    {
        if (! is_array($items)) {
            return 0;
        }

        $written = 0;

        foreach ($items as $item) {
            if (! is_array($item) || ($item['external_id'] ?? '') === '') {
                continue;
            }

            $productId = $item['product_external_id'] === null ? null : CommerceProduct::withoutGlobalScopes()
                ->where('external_account_id', $store->getKey())
                ->where('external_id', (string) $item['product_external_id'])
                ->value('id');

            CommerceOrderItem::withoutGlobalScopes()->updateOrCreate(
                ['commerce_order_id' => $order->getKey(), 'external_id' => (string) $item['external_id']],
                [
                    'tenant_id' => $store->tenant_id,
                    // Null when the product sweep has not run yet; the line still records the
                    // platform's product id, so a later sweep can join without re-reading the order.
                    'commerce_product_id' => $productId,
                    'product_external_id' => $item['product_external_id'] ?? null,
                    'sku' => $item['sku'] ?? null,
                    'name' => (string) ($item['name'] ?? $item['external_id']),
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? null,
                    'total' => $item['total'] ?? null,
                ],
            );

            $written++;
        }

        return $written;
    }

    private function time(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // A date we cannot read is not worth failing an order over; the raw payload keeps it.
            return null;
        }
    }
}
