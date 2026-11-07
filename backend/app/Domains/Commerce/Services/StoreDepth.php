<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * STORE-ANALYTICS-DEPTH-001 — the store's own reading of a period, the depth of a merchant's report.
 *
 * The store is the authority on orders and sales here. Every figure comes from {@see ProjectOrders}' deduplicated
 * orders; nothing is taken from GA4 or a platform except the visit count of one conversion rate, which names the
 * system that counted the visits. A figure no order states is null, never 0, and a section the provider cannot
 * supply states why. No customer name, email or phone is read or returned.
 */
final class StoreDepth
{
    /** Below these a lifetime value is one customer's story, not a figure about the shop. */
    private const LTV_MIN_CUSTOMERS = 30;

    private const LTV_MIN_LEDGER_DAYS = 90;

    private const LTV_MIN_ORDERS_WITH_CUSTOMER = 0.8;

    private const RANKED = 10;

    public function __construct(
        private readonly ProjectStores $stores,
        private readonly ProjectOrders $orders,
        private readonly CommerceCurrency $currency,
        private readonly ReportingTimezone $timezones,
        private readonly Ga4SiteAnalytics $site,
    ) {}

    /**
     * @param  bool  $excludeDemo  a report that goes to a client never rests on seeded rows
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $excludeDemo = false): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $days = (int) Carbon::parse($window['from_date'])->diffInDays(Carbon::parse($window['to_date'])) + 1;
        $prevTo = Carbon::parse($window['from_date'])->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);
        $prevWindow = $this->timezones->window($projectId, $prevFrom, $prevTo);
        $period = ['from' => $window['from_date'], 'to' => $window['to_date'], 'timezone' => $window['timezone']];
        $previous = ['from' => $prevWindow['from_date'], 'to' => $prevWindow['to_date']];

        if ($this->stores->forProject($tenantId, $projectId)->isEmpty()) {
            return ['state' => 'no_store', 'period' => $period, 'previous_period' => $previous];
        }

        $loaded = $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to']);
        $all = $loaded['orders'];
        $demo = $all->filter(static fn (CommerceOrder $o): bool => (bool) $o->is_demo)->count();
        $current = $excludeDemo ? $all->reject(static fn (CommerceOrder $o): bool => (bool) $o->is_demo)->values() : $all;
        $before = $this->orders->forWindow($tenantId, $projectId, $prevWindow['from'], $prevWindow['to'])['orders'];
        if ($excludeDemo) {
            $before = $before->reject(static fn (CommerceOrder $o): bool => (bool) $o->is_demo)->values();
        }

        $basis = [
            'source' => 'store_confirmed',
            'label_ar' => 'ما أكّده المتجر',
            'label_en' => 'Store-confirmed',
            'demo_orders' => $demo,
            'live_orders' => $all->count() - $demo,
            'demo_excluded' => $excludeDemo && $demo > 0,
            'kind' => $demo === 0 ? 'live' : ($demo === $all->count() ? 'demo' : 'mixed'),
        ];

        if ($current->isEmpty()) {
            return ['state' => 'empty', 'period' => $period, 'previous_period' => $previous, 'basis' => $basis];
        }

        $now = $this->tally($current);
        $was = $this->tally($before);
        $items = $this->itemRows($tenantId, $current->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null));
        $liveCount = $now['live'];
        $units = $items->sum('quantity');

        return [
            'state' => 'ready',
            'period' => $period,
            'previous_period' => $previous,
            'currency' => $this->currency->reportingCurrencyFor($projectId),
            'basis' => $basis,
            'sales' => array_combine(
                ['gross', 'net', 'discounts', 'shipping', 'tax', 'refunded'],
                array_map(fn (string $k): array => $this->compared($now[$k], $before->isEmpty() ? null : $was[$k]), ['gross', 'net', 'discounts', 'shipping', 'tax', 'refunded']),
            ),
            'orders' => [
                'total' => $current->count(),
                'live' => $liveCount,
                'confirmed' => $now['confirmed'],
                'pending' => $liveCount - $now['confirmed'],
                'cancelled' => $current->count() - $liveCount,
                'refunded' => $now['refunded_orders'],
                'previous_live' => $before->isEmpty() ? null : $was['live'],
            ],
            'averages' => [
                'aov' => $liveCount > 0 && $now['net'] !== null ? round($now['net'] / $liveCount, 2) : null,
                'items_per_order' => $items->isEmpty() || $liveCount === 0 ? null : round($units / $liveCount, 2),
                'cancellation_rate' => round(($current->count() - $liveCount) / $current->count(), 4),
                'refund_rate' => $liveCount > 0 ? round($now['refunded_orders'] / $liveCount, 4) : null,
                'discount_share' => $now['discounts'] !== null && $now['gross'] !== null && $now['gross'] + $now['discounts'] > 0 ? round($now['discounts'] / ($now['gross'] + $now['discounts']), 4) : null,
            ],
            'conversion' => $this->conversion($tenantId, $projectId, $window, $now['confirmed']),
            'products' => $this->products($projectId, $items),
            'abandoned' => $this->abandoned($projectId, $window),
            'customers' => $this->customers($projectId, $current, $window['from'], $excludeDemo, $loaded['duplicates_collapsed']),
            'trend' => $this->trend($current, $window['timezone']),
            'coverage' => [
                'orders_with_money_withheld' => $now['withheld'],
                'money_withheld_currencies' => $current->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null && $o->moneyWithheld())->pluck('original_currency')->filter()->unique()->values()->all(),
                'duplicate_orders_collapsed' => $loaded['duplicates_collapsed'],
            ],
        ];
    }

    /**
     * @param  Collection<int, CommerceOrder>  $orders
     * @return array<string, mixed>
     */
    private function tally(Collection $orders): array
    {
        $live = $orders->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null);
        $stated = static function (string $column) use ($live): ?float {
            $values = $live->map(static fn (CommerceOrder $o) => $o->{$column})->filter(static fn ($v): bool => $v !== null);

            return $values->isEmpty() ? null : round((float) $values->sum(static fn ($v): float => (float) $v), 2);
        };
        $net = $live->map(static fn (CommerceOrder $o) => $o->netRevenue())->filter(static fn ($v): bool => $v !== null);

        return [
            'live' => $live->count(),
            'confirmed' => $live->filter(static fn (CommerceOrder $o): bool => in_array(strtolower((string) $o->status), StoreFunnelService::PAID_STATUSES, true))->count(),
            'refunded_orders' => $live->filter(static fn (CommerceOrder $o): bool => (float) $o->refunded_total > 0)->count(),
            'withheld' => $live->filter(static fn (CommerceOrder $o): bool => $o->moneyWithheld())->count(),
            'gross' => $stated('total'),
            'net' => $net->isEmpty() ? null : round((float) $net->sum(), 2),
            'discounts' => $stated('discount_total'),
            'shipping' => $stated('shipping_total'),
            'tax' => $stated('tax_total'),
            'refunded' => $stated('refunded_total'),
        ];
    }

    /** @return array{current: ?float, previous: ?float, change_pct: ?float} */
    private function compared(?float $current, ?float $previous): array
    {
        return [
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $current !== null && $previous !== null && $previous != 0.0 ? round(($current - $previous) / abs($previous), 4) : null,
        ];
    }

    /**
     * @param  Collection<int, CommerceOrder>  $live
     * @return Collection<int, mixed>
     */
    private function itemRows(string $tenantId, Collection $live): Collection
    {
        $ids = $live->pluck('id')->all();
        if ($ids === []) {
            return collect();
        }

        return CommerceOrderItem::withoutGlobalScopes()
            ->leftJoin('commerce_products', 'commerce_products.id', '=', 'commerce_order_items.commerce_product_id')
            ->where('commerce_order_items.tenant_id', $tenantId)
            ->whereIn('commerce_order_items.commerce_order_id', $ids)
            ->selectRaw('COALESCE(commerce_order_items.commerce_product_id::text, commerce_order_items.name) AS item_key')
            ->selectRaw('MAX(commerce_order_items.name) AS item_name, MAX(commerce_products.category) AS category')
            ->selectRaw('SUM(commerce_order_items.quantity) AS quantity, SUM(commerce_order_items.total) AS revenue')
            ->selectRaw('COUNT(DISTINCT commerce_order_items.commerce_order_id) AS orders')
            ->groupByRaw('COALESCE(commerce_order_items.commerce_product_id::text, commerce_order_items.name)')
            ->toBase()
            ->get()
            ->map(static fn (object $r): array => [
                'key' => (string) $r->item_key,
                'name' => (string) $r->item_name,
                'category' => $r->category === null ? null : (string) $r->category,
                'quantity' => round((float) $r->quantity, 2),
                'revenue' => round((float) $r->revenue, 2),
                'orders' => (int) $r->orders,
            ]);
    }

    /**
     * @param  Collection<int, mixed>  $items  rows of key, name, category, quantity, revenue and orders
     * @return array<string, mixed>
     */
    private function products(string $projectId, Collection $items): array
    {
        $public = static fn (array $r): array => ['name' => $r['name'], 'quantity' => $r['quantity'], 'revenue' => $r['revenue'], 'orders' => $r['orders']];
        $byRevenue = $items->sortByDesc('revenue')->values();
        $top = $byRevenue->take(self::RANKED)->pluck('key')->all();
        // The lowest earners of what sold, without repeating the leaders unless the catalogue is too small to tell them apart.
        $rest = $items->reject(static fn (array $r): bool => in_array($r['key'], $top, true));
        $weak = ($rest->isNotEmpty() ? $rest : ($items->count() > 1 ? $items : collect()))->sortBy('revenue')->take(self::RANKED)->values();

        $unsold = BoundAccountVisibility::apply(CommerceProduct::withoutGlobalScopes()->where('project_id', $projectId)->where('status', 'active'), 'commerce_products')
            ->whereNotIn('id', $items->pluck('key')->filter(static fn (string $k): bool => preg_match('/^[0-9a-f-]{36}$/', $k) === 1)->all());

        $categorised = $items->filter(static fn (array $r): bool => $r['category'] !== null && $r['category'] !== '');
        $categories = $categorised->groupBy('category')->map(static fn (Collection $g, $category): array => [
            'category' => (string) $category,
            'revenue' => round((float) $g->sum('revenue'), 2),
            'quantity' => round((float) $g->sum('quantity'), 2),
            'products' => $g->count(),
        ])->sortByDesc('revenue')->values()->all();

        return [
            'best_by_revenue' => $byRevenue->take(self::RANKED)->map($public)->all(),
            'best_by_orders' => $items->sortByDesc('orders')->values()->take(self::RANKED)->map($public)->all(),
            'weak' => $weak->map($public)->all(),
            'unsold_active' => ['count' => (clone $unsold)->count(), 'sample' => (clone $unsold)->orderBy('name')->limit(self::RANKED)->pluck('name')->all()],
            'categories' => $categories,
            'categories_note' => $categories === [] ? ['ar' => 'لا تحمل منتجات هذه الفترة تصنيفًا في المتجر.', 'en' => 'The products of this period carry no category in the store.'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array<string, mixed>
     */
    private function abandoned(string $projectId, array $window): array
    {
        $carts = BoundAccountVisibility::apply(
            CommerceAbandonedCart::withoutGlobalScopes()->where('project_id', $projectId)->whereBetween('abandoned_at', [$window['from'], $window['to']]),
            'commerce_abandoned_carts',
        )->toBase()->selectRaw('COUNT(*) AS carts, SUM(total) AS value')->first();

        return [
            'carts' => (int) ($carts->carts ?? 0),
            'value' => isset($carts->value) ? round((float) $carts->value, 2) : null,
            // No provider sync stores a cart's lines, only its total and item count.
            'by_product' => null,
            'by_product_reason' => ['ar' => 'لا يحفظ النظام أصناف السلة المتروكة من المتجر، بل إجماليها وعدد قطعها فقط.', 'en' => 'The store sync keeps an abandoned cart’s total and item count, not its products.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $window
     * @return array{value: ?float, confirmed_orders: int, visits: ?float, visits_source: ?string, label_ar: ?string, label_en: ?string}
     */
    private function conversion(string $tenantId, string $projectId, array $window, int $confirmed): array
    {
        $visits = $this->site->funnelFigures($tenantId, $projectId, $window['from_date'], $window['to_date'])['sessions'] ?? null;
        $source = $visits === null ? null : 'ga4';

        if ($visits === null) {
            $reported = DailyMetric::withoutGlobalScopes()
                ->where('project_id', $projectId)
                ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))
                ->where('metric_key', 'landing_page_views')
                ->whereBetween('metric_date', [$window['from_date'], $window['to_date']])
                ->sum('value');
            if ((float) $reported > 0) {
                $visits = (float) $reported;
                $source = 'ad_platforms';
            }
        }

        return [
            'value' => $visits !== null && $visits > 0 ? round($confirmed / $visits, 4) : null,
            'confirmed_orders' => $confirmed,
            'visits' => $visits,
            'visits_source' => $source,
            'label_ar' => match ($source) {
                'ga4' => 'طلبات المتجر المؤكدة ÷ جلسات الموقع',
                'ad_platforms' => 'طلبات المتجر المؤكدة ÷ زيارات صفحة الهبوط كما أبلغت المنصات (مدفوعة فقط)',
                default => null,
            },
            'label_en' => match ($source) {
                'ga4' => 'Store-confirmed orders ÷ website sessions',
                'ad_platforms' => 'Store-confirmed orders ÷ landing-page views the platforms reported (paid only)',
                default => null,
            },
        ];
    }

    /**
     * @param  Collection<int, CommerceOrder>  $orders
     * @return array<string, mixed>
     */
    private function customers(string $projectId, Collection $orders, Carbon $windowStart, bool $excludeDemo, int $duplicates): array
    {
        $live = $orders->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null);
        $ids = $live->pluck('commerce_customer_id')->filter()->unique()->values();
        $without = $live->whereNull('commerce_customer_id')->count();

        // The whole ledger per customer, for repeat purchase and lifetime value: names and contact details are never selected.
        $ledger = BoundAccountVisibility::apply(
            CommerceOrder::withoutGlobalScopes()->where('project_id', $projectId)->whereNull('cancelled_at')->whereNotNull('commerce_customer_id')
                ->when($excludeDemo, static fn ($q) => $q->where('is_demo', false)),
            'commerce_orders',
        )->toBase()->groupBy('commerce_customer_id')
            ->selectRaw('commerce_customer_id, COUNT(*) AS orders, MIN(placed_at) AS first_order, SUM(COALESCE(total, 0) - COALESCE(refunded_total, 0)) AS net')
            ->get();

        $firstOrder = $ledger->pluck('first_order', 'commerce_customer_id');
        $firstSeen = $ids->isEmpty() ? collect() : CommerceCustomer::withoutGlobalScopes()->whereIn('id', $ids->all())->pluck('first_seen_at', 'id');
        $returning = $ids->filter(static function ($id) use ($firstOrder, $firstSeen, $windowStart): bool {
            $earliest = array_filter([$firstOrder[$id] ?? null, $firstSeen[$id] ?? null]);
            foreach ($earliest as $moment) {
                if (Carbon::parse((string) $moment)->lt($windowStart)) {
                    return true;
                }
            }

            return false;
        })->count();

        $customers = $ledger->count();
        $repeat = $ledger->filter(static fn (object $r): bool => (int) $r->orders >= 2)->count();
        $since = $ledger->min('first_order');
        $reason = match (true) {
            $duplicates > 0 => 'shop_connected_twice',
            $customers < self::LTV_MIN_CUSTOMERS => 'too_few_customers',
            $since === null || Carbon::parse((string) $since)->diffInDays(Carbon::now()) < self::LTV_MIN_LEDGER_DAYS => 'ledger_too_short',
            $live->isNotEmpty() && ($live->count() - $without) / $live->count() < self::LTV_MIN_ORDERS_WITH_CUSTOMER => 'orders_without_customer',
            default => null,
        };

        return [
            'new' => $ids->count() - $returning,
            'returning' => $returning,
            'without_customer' => $without,
            'repeat_rate' => $customers > 0 ? round($repeat / $customers, 4) : null,
            'customers_in_ledger' => $customers,
            'ledger_since' => $since === null ? null : Carbon::parse((string) $since)->toDateString(),
            'lifetime_value' => $reason === null ? round((float) $ledger->sum(static fn (object $r): float => (float) $r->net) / $customers, 2) : null,
            'lifetime_value_reason' => $reason,
            'lifetime_value_basis' => ['ar' => 'صافي مبيعات كل عميل منذ بداية سجل المتجر هنا، وليس توقعًا مستقبليًا.', 'en' => 'Each customer’s net sales since this store’s ledger begins here. Not a forecast.'],
        ];
    }

    /**
     * @param  Collection<int, CommerceOrder>  $orders
     * @return list<array{date: string, orders: int, net: float}>
     */
    private function trend(Collection $orders, string $timezone): array
    {
        $days = [];
        foreach ($orders as $order) {
            if ($order->cancelled_at !== null || $order->placed_at === null) {
                continue;
            }
            $date = $order->placed_at->copy()->setTimezone($timezone)->toDateString();
            $days[$date] ??= ['date' => $date, 'orders' => 0, 'net' => 0.0];
            $days[$date]['orders']++;
            $days[$date]['net'] += $order->netRevenue() ?? 0.0;
        }
        ksort($days);

        return array_map(static fn (array $d): array => ['date' => $d['date'], 'orders' => $d['orders'], 'net' => round($d['net'], 2)], array_values($days));
    }
}
