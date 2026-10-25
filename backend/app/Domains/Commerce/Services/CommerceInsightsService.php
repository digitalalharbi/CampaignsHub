<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * COMMERCE-INSIGHTS-001 — what the STORE itself says about a project's sales, from the merchant's own ledger.
 *
 * One read over the orders the project's stores filed (through {@see ProjectOrders}, so a shop connected twice counts once): sales
 * (gross, discounts, shipping, tax, refunds, net), orders, average order value and growth against the previous period, the daily
 * trend, products and SKUs, categories, cities, new against returning customers, coupons and abandoned carts.
 *
 * Rules held here and not left to a caller:
 *  - Money is the project's REPORTING currency (converted at import). An order whose rate could not be vouched for is WITHHELD from
 *    every money sum and counted in `provenance.orders_with_money_withheld`; a total that is short says so.
 *  - A cancelled order is not a sale. A refund reduces net sales and is shown on its own; refund rules are `CommerceOrder::netRevenue`.
 *  - The window is the CLIENT's days (reporting timezone), and the trend groups by the merchant's own `placed_on` day.
 *  - Absence is stated, never zero-filled: no cost data → `profitability.available = false`; no customer city → counted under
 *    `unknown`; a provider that reports no carts → `abandoned_carts.supported = false`; shipping DESTINATION is not stored, so
 *    `geography.basis = customer_city` and says so; retention needs repeat history this product does not hold → `null` with a reason.
 *  - Nothing here is an advertising figure and nothing is summed with one.
 */
final class CommerceInsightsService
{
    private const TOP = 10;

    public function __construct(
        private readonly ProjectStores $stores,
        private readonly ProjectOrders $orders,
        private readonly CommerceCurrency $currency,
        private readonly ReportingTimezone $timezones,
    ) {}

    /** @return array<string,mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $compare = true): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $days = max(1, Carbon::parse($window['from_date'])->diffInDays(Carbon::parse($window['to_date'])) + 1);
        $stores = $this->stores->forProject($tenantId, $projectId);

        $loaded = $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to']);
        /** @var Collection<int,CommerceOrder> $orders */
        $orders = $loaded['orders'];
        $current = $this->totals($orders);

        $previous = null;
        $growth = null;
        $prevWindow = null;
        if ($compare) {
            $prevTo = Carbon::parse($window['from_date'])->subDay();
            $prevFrom = $prevTo->copy()->subDays($days - 1);
            $prevWindow = $this->timezones->window($projectId, $prevFrom, $prevTo);
            $previous = $this->totals($this->orders->forWindow($tenantId, $projectId, $prevWindow['from'], $prevWindow['to'])['orders']);
            $growth = $this->growth($current, $previous);
        }

        $live = $orders->filter(fn (CommerceOrder $o): bool => $o->cancelled_at === null);

        return [
            'window' => ['from' => $window['from_date'], 'to' => $window['to_date'], 'timezone' => $window['timezone'], 'days' => $days,
                'previous' => $prevWindow === null ? null : ['from' => $prevWindow['from_date'], 'to' => $prevWindow['to_date']]],
            'provenance' => [
                'source' => 'store',
                'currency' => $this->currency->reportingCurrencyFor($projectId),
                'stores' => $stores->map(fn (ExternalAccount $s): array => ['id' => (string) $s->getKey(), 'name' => (string) $s->name, 'provider' => (string) $s->provider, 'last_synced_at' => $s->last_synced_at?->toIso8601String()])->values()->all(),
                'has_store' => $stores->isNotEmpty(),
                'duplicates_collapsed' => (int) ($loaded['duplicates_collapsed'] ?? 0),
                'orders_with_money_withheld' => $live->filter(fn (CommerceOrder $o): bool => $o->moneyWithheld())->count(),
                'orders_with_assumed_timezone' => $orders->filter(fn (CommerceOrder $o): bool => $o->time_source === 'assumed_utc')->count(),
            ],
            'totals' => $current,
            'previous' => $previous,
            'growth' => $growth,
            'trend' => $this->trend($live),
            'products' => $this->products($live),
            'categories' => $this->categories($live),
            'geography' => $this->geography($live),
            'customers' => $this->customers($live, $window['from'], $window['to']),
            'coupons' => $this->coupons($live),
            'abandoned_carts' => $this->abandoned($projectId, $stores, $window['from'], $window['to']),
            'profitability' => ['available' => false, 'reason' => 'no_cost_data'],
        ];
    }

    /**
     * @param  Collection<int,CommerceOrder>  $orders
     * @return array<string,int|float|null>
     */
    private function totals(Collection $orders): array
    {
        $live = $orders->filter(fn (CommerceOrder $o): bool => $o->cancelled_at === null);
        $priced = $live->reject(fn (CommerceOrder $o): bool => $o->moneyWithheld());
        $sum = fn (string $f): float => round((float) $priced->sum(fn (CommerceOrder $o): float => (float) $o->{$f}), 2);

        $net = round((float) $priced->sum(fn (CommerceOrder $o): float => $o->netRevenue() ?? 0.0), 2);
        $refunds = round((float) $priced->sum(fn (CommerceOrder $o): float => (float) $o->refunded_total), 2);
        $gross = $sum('total');

        return [
            'orders' => $live->count(),
            'cancelled_orders' => $orders->count() - $live->count(),
            'orders_priced' => $priced->count(),
            'gross_sales' => $gross,
            'subtotal' => $sum('subtotal'),
            'discounts' => $sum('discount_total'),
            'shipping' => $sum('shipping_total'),
            'tax' => $sum('tax_total'),
            'refunds' => $refunds,
            'net_sales' => $net,
            'aov' => $priced->count() === 0 ? null : round($net / $priced->count(), 2),
            'refund_rate' => $gross > 0 ? round($refunds / $gross, 4) : null,
        ];
    }

    /**
     * Change against the previous period; null when the previous figure is zero or absent (a percentage of nothing is not a number).
     *
     * @param  array<string,mixed>  $now
     * @param  array<string,mixed>  $before
     * @return array<string,float|null>
     */
    private function growth(array $now, array $before): array
    {
        $out = [];
        foreach (['orders', 'gross_sales', 'discounts', 'refunds', 'net_sales', 'aov'] as $k) {
            $b = $before[$k] ?? null;
            $n = $now[$k] ?? null;
            $out[$k] = ($b === null || (float) $b == 0.0 || $n === null) ? null : round((((float) $n - (float) $b) / (float) $b) * 100, 1);
        }

        return $out;
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return list<array{date:string,orders:int,net_sales:float}>
     */
    private function trend(Collection $live): array
    {
        return $live->groupBy(fn (CommerceOrder $o): string => $o->placed_on?->toDateString() ?? $o->placed_at->toDateString())
            ->map(fn (Collection $day, string $date): array => [
                'date' => $date, 'orders' => $day->count(),
                'net_sales' => round((float) $day->sum(fn (CommerceOrder $o): float => $o->netRevenue() ?? 0.0), 2),
            ])->sortKeys()->values()->all();
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return Collection<int,CommerceOrderItem>
     */
    private function lines(Collection $live): Collection
    {
        $priced = $live->reject(fn (CommerceOrder $o): bool => $o->moneyWithheld())->keyBy(fn (CommerceOrder $o): string => (string) $o->getKey());
        if ($priced->isEmpty()) {
            return collect();
        }

        return CommerceOrderItem::withoutGlobalScopes()->whereIn('commerce_order_id', $priced->keys()->all())->get();
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return array<string,mixed>
     */
    private function products(Collection $live): array
    {
        $items = $this->lines($live);
        $group = function (callable $key, string $label) use ($items): array {
            $rows = $items->groupBy($key)->map(function (Collection $g, string $k): array {
                return [
                    'key' => $k, 'name' => (string) ($g->first()->name ?? $k), 'sku' => $g->first()->sku,
                    'quantity' => (int) $g->sum('quantity'), 'sales' => round((float) $g->sum(fn ($i): float => (float) $i->total), 2),
                    'orders' => $g->pluck('commerce_order_id')->unique()->count(),
                ];
            })->values();

            return ['basis' => $label, 'count' => $rows->count(),
                'best' => $rows->sortByDesc('sales')->take(self::TOP)->values()->all(),
                // Worst = the lowest sellers that DID sell; a product with no sale is not in an order line at all.
                'worst' => $rows->sortBy('sales')->take(self::TOP)->values()->all()];
        };

        return [
            'by_product' => $group(fn ($i): string => (string) ($i->product_external_id ?: $i->sku ?: $i->name), 'product'),
            'by_sku' => $group(fn ($i): string => (string) ($i->sku ?: 'no_sku'), 'sku'),
            'units' => (int) $items->sum('quantity'),
            'lines_without_sku' => $items->filter(fn ($i): bool => empty($i->sku))->count(),
        ];
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return array<string,mixed>
     */
    private function categories(Collection $live): array
    {
        $items = $this->lines($live);
        $productIds = $items->pluck('commerce_product_id')->filter()->unique()->all();
        $category = $productIds === [] ? collect() : CommerceProduct::withoutGlobalScopes()->whereIn('id', $productIds)->pluck('category', 'id');

        $rows = $items->groupBy(fn ($i): string => (string) ($category->get($i->commerce_product_id) ?: 'uncategorised'))
            ->map(fn (Collection $g, string $k): array => ['category' => $k, 'quantity' => (int) $g->sum('quantity'), 'sales' => round((float) $g->sum(fn ($i): float => (float) $i->total), 2)])
            ->sortByDesc('sales')->values();

        return ['rows' => $rows->take(self::TOP * 2)->all(), 'uncategorised_share' => $rows->isEmpty() ? null : round((float) ($rows->firstWhere('category', 'uncategorised')['sales'] ?? 0) / max(0.01, (float) $rows->sum('sales')), 4)];
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return array<string,mixed>
     */
    private function geography(Collection $live): array
    {
        $ids = $live->pluck('commerce_customer_id')->filter()->unique()->all();
        $customers = $ids === [] ? collect() : CommerceCustomer::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'city', 'country'])->keyBy('id');

        $rows = $live->groupBy(function (CommerceOrder $o) use ($customers): string {
            $c = $customers->get($o->commerce_customer_id);

            return trim((string) ($c->city ?? '')) === '' ? 'unknown' : trim((string) $c->city);
        })->map(fn (Collection $g, string $city): array => [
            'city' => $city, 'orders' => $g->count(),
            'net_sales' => round((float) $g->sum(fn (CommerceOrder $o): float => $o->netRevenue() ?? 0.0), 2),
        ])->sortByDesc('orders')->values();

        $total = max(1, $live->count());

        return [
            // Shipping destination is not stored: the only place this product knows is the CUSTOMER's own city.
            'basis' => 'customer_city', 'shipping_destination' => 'not_available',
            'rows' => $rows->take(self::TOP * 2)->map(fn (array $r): array => $r + ['share' => round($r['orders'] / $total, 4)])->all(),
            'unknown_orders' => (int) ($rows->firstWhere('city', 'unknown')['orders'] ?? 0),
        ];
    }

    /**
     * New = first seen inside the window; returning = ordered inside it but first seen before. Retention/cohorts need the repeat
     * history of cohorts across periods, which is not held — stated, not estimated.
     *
     * @param  Collection<int,CommerceOrder>  $live
     * @return array<string,mixed>
     */
    private function customers(Collection $live, Carbon $from, Carbon $to): array
    {
        $ids = $live->pluck('commerce_customer_id')->filter()->unique()->all();
        $customers = $ids === [] ? collect() : CommerceCustomer::withoutGlobalScopes()->whereIn('id', $ids)->get(['id', 'first_seen_at'])->keyBy('id');

        $isNew = fn (CommerceOrder $o): bool => ($c = $customers->get($o->commerce_customer_id)) !== null && $c->first_seen_at !== null && $c->first_seen_at->between($from, $to);
        $known = $live->filter(fn (CommerceOrder $o): bool => $customers->has($o->commerce_customer_id));
        $new = $known->filter($isNew);
        $returning = $known->reject($isNew);
        $net = fn (Collection $c): float => round((float) $c->sum(fn (CommerceOrder $o): float => $o->netRevenue() ?? 0.0), 2);

        return [
            'new' => ['customers' => $new->pluck('commerce_customer_id')->unique()->count(), 'orders' => $new->count(), 'net_sales' => $net($new)],
            'returning' => ['customers' => $returning->pluck('commerce_customer_id')->unique()->count(), 'orders' => $returning->count(), 'net_sales' => $net($returning)],
            'orders_without_customer' => $live->count() - $known->count(),
            'retention' => null,
            'retention_reason' => 'needs_cohort_history',
        ];
    }

    /**
     * @param  Collection<int,CommerceOrder>  $live
     * @return array<string,mixed>
     */
    private function coupons(Collection $live): array
    {
        $with = $live->filter(fn (CommerceOrder $o): bool => trim((string) $o->coupon_code) !== '');
        $rows = $with->groupBy(fn (CommerceOrder $o): string => strtoupper(trim((string) $o->coupon_code)))
            ->map(fn (Collection $g, string $code): array => [
                'code' => $code, 'orders' => $g->count(),
                'discount' => round((float) $g->sum(fn (CommerceOrder $o): float => (float) $o->discount_total), 2),
                'net_sales' => round((float) $g->sum(fn (CommerceOrder $o): float => $o->netRevenue() ?? 0.0), 2),
            ])->sortByDesc('orders')->values();

        return ['orders_with_coupon' => $with->count(), 'share_of_orders' => $live->isEmpty() ? null : round($with->count() / $live->count(), 4), 'rows' => $rows->take(self::TOP)->all()];
    }

    /**
     * @param  Collection<int,ExternalAccount>  $stores
     * @return array<string,mixed>
     */
    private function abandoned(string $projectId, Collection $stores, Carbon $from, Carbon $to): array
    {
        $supported = $stores->isNotEmpty() && $stores->contains(fn (ExternalAccount $s): bool => $s->provider !== 'zid');
        if (! $supported) {
            return ['supported' => false, 'count' => null, 'value' => null];
        }
        $carts = CommerceAbandonedCart::withoutGlobalScopes()->where('project_id', $projectId)->whereBetween('abandoned_at', [$from, $to])->get(['total']);

        return [
            'supported' => true, 'count' => $carts->count(), 'value' => round((float) $carts->sum(fn ($c): float => (float) $c->total), 2),
            // A store on a platform with no cart endpoint makes the count partial; the client is told, not shown a perfect rate.
            'complete' => $stores->every(fn (ExternalAccount $s): bool => $s->provider !== 'zid'),
        ];
    }
}
