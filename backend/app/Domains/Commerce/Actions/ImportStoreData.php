<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Actions;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Services\CommerceCurrency;
use App\Domains\Commerce\Services\OrderAttributionResolver;
use App\Domains\Commerce\Services\StoreTime;
use App\Domains\Commerce\ValueObjects\Attribution;
use App\Domains\Commerce\ValueObjects\MoneyConversion;
use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 *
 * ## Money is converted HERE, once (COMMERCE-FX-001)
 *
 * The amount columns hold the project's reporting currency and the provider's own figures sit beside
 * them in `original_*`. Every reader — the funnel, the dashboard's store block, the attribution
 * report, the best-seller table, the public report links — sums the amount columns and is therefore
 * correct without knowing this happened, which is the same guarantee FX-001 gave the ad screens. A
 * rate that cannot be vouched for withholds the converted figure rather than inventing one.
 */
final class ImportStoreData
{
    public function __construct(
        private readonly OrderAttributionResolver $attribution,
        private readonly CommerceCurrency $currency,
        private readonly StoreTime $time,
    ) {}

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

                // `?? null` because a row without the key at all is a legitimate shape — the ternary
                // already handles «no attribution», and reading the missing key threw instead.
                $attribution = ($row['attribution'] ?? null) instanceof Attribution
                    ? $row['attribution']
                    : Attribution::read();

                /*
                 * COMMERCE-TZ-001 — the instant, the merchant's own date, and which clock said so.
                 *
                 * Resolved here rather than by each connector so Salla's `{date, timezone}` wrapper
                 * and Zid's string land on the same three columns, and so the zone chain — payload,
                 * store, client, assumed — is written down once.
                 */
                $placed = $this->time->resolve($row['placed_at'] ?? null, $store->timezone, $projectId);
                $placedAt = $placed?->instant;

                /*
                 * One rate for the whole order, taken as of the day it was PLACED (COMMERCE-FX-001).
                 *
                 * Not the day of the sweep: a ninety-day window is re-read constantly, and pricing
                 * January's revenue at August's rate would make last month's report change every time
                 * anybody opened it.
                 */
                $money = $this->currency->for($projectId, $row['currency'] ?? $store->currency, $placedAt);

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
                        ...($placed?->columns('placed_at', 'placed_on') ?? ['placed_at' => null, 'placed_at_timezone' => null, 'placed_on' => null, 'time_source' => null]),
                        ...$money->columns(),
                        'subtotal' => $money->convert($row['subtotal'] ?? null),
                        'shipping_total' => $money->convert($row['shipping_total'] ?? null),
                        'tax_total' => $money->convert($row['tax_total'] ?? null),
                        'discount_total' => $money->convert($row['discount_total'] ?? null),
                        'total' => $money->convert($row['total'] ?? null),
                        'refunded_total' => $money->convert($row['refunded_total'] ?? null),
                        'original_subtotal' => $money->original($row['subtotal'] ?? null),
                        'original_shipping_total' => $money->original($row['shipping_total'] ?? null),
                        'original_tax_total' => $money->original($row['tax_total'] ?? null),
                        'original_discount_total' => $money->original($row['discount_total'] ?? null),
                        'original_total' => $money->original($row['total'] ?? null),
                        'original_refunded_total' => $money->original($row['refunded_total'] ?? null),
                        // Same chain as `placed_at`: a refund and a cancellation are moments too,
                        // and a wall clock read in the server's zone is wrong for both.
                        'refunded_at' => $this->time->resolve($row['refunded_at'] ?? null, $store->timezone, $projectId)?->instant,
                        'cancelled_at' => $this->time->resolve($row['cancelled_at'] ?? null, $store->timezone, $projectId)?->instant,
                        ...$attribution->toColumns(),
                        'is_demo' => false,
                        'last_synced_at' => Carbon::now(),
                    ],
                );

                $counts['items'] += $this->items($store, $order, $row['items'] ?? [], $money);

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

                // `?? null` because a row without the key at all is a legitimate shape — the ternary
                // already handles «no attribution», and reading the missing key threw instead.
                $attribution = ($row['attribution'] ?? null) instanceof Attribution
                    ? $row['attribution']
                    : Attribution::read();

                $abandoned = $this->time->resolve($row['abandoned_at'] ?? null, $store->timezone, $projectId);
                $abandonedAt = $abandoned?->instant;
                $money = $this->currency->for($projectId, $row['currency'] ?? $store->currency, $abandonedAt);

                CommerceAbandonedCart::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->getKey(), 'external_id' => $externalId],
                    [
                        'tenant_id' => $store->tenant_id,
                        'project_id' => $projectId,
                        'provider' => $store->provider,
                        ...($abandoned?->columns('abandoned_at', 'abandoned_on') ?? ['abandoned_at' => null, 'abandoned_at_timezone' => null, 'abandoned_on' => null, 'time_source' => null]),
                        ...$money->columns(),
                        'total' => $money->convert($row['total'] ?? null),
                        'original_total' => $money->original($row['total'] ?? null),
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
                /*
                 * A lifetime total the platform computed, kept in the currency the platform computed
                 * it in and LABELLED with it (COMMERCE-FX-001).
                 *
                 * Not converted, because it spans every order the customer ever placed — including
                 * ones outside any window this product has rates for — so a single dated rate would
                 * be a guess dressed as a figure. It is never summed across shops; the currency is
                 * carried so it can never be read as a bare number either.
                 */
                'total_spent' => $row['total_spent'] ?? null,
                'currency' => $row['currency'] ?? $store->currency,
                'first_seen_at' => $this->time->resolve($row['first_seen_at'] ?? null, $store->timezone, $projectId)?->instant,
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
     * Order lines, converted at their ORDER's rate — never at one of their own.
     *
     * A line is part of a sale, not a sale of its own: resolving a second rate for it would let the
     * lines and the total they belong to disagree, and the best-seller table would stop adding up to
     * the revenue printed above it.
     *
     * @return int items written
     */
    private function items(ExternalAccount $store, CommerceOrder $order, mixed $items, MoneyConversion $money): int
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
                    // A count of things, not money: never converted.
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $money->convert($item['unit_price'] ?? null),
                    'total' => $money->convert($item['total'] ?? null),
                    'original_unit_price' => $money->original($item['unit_price'] ?? null),
                    'original_total' => $money->original($item['total'] ?? null),
                ],
            );

            $written++;
        }

        return $written;
    }
}
