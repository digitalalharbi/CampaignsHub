<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Models\DailyMetric;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FUNNEL-001 — «الفانل والمتجر»: impression to riyal, with the source of every number attached.
 *
 * ## Why each stage carries its source instead of just a figure
 *
 * The nine stages are not measured by one thing. Impressions and clicks come from the ad platforms.
 * Orders and revenue come from the store. Add-to-cart can come from EITHER — the store knows how many
 * carts existed, and the ad platform's pixel reports how many it saw — and those two numbers are
 * routinely different, because the pixel misses ad-blocked sessions and the store misses nothing.
 * Product views and checkout starts are measured by NEITHER, on this product, today.
 *
 * A funnel that printed nine numbers with no provenance would make all of that invisible, and the
 * first question any competent media buyer asks — «من أين جاء هذا الرقم؟» — would have no answer. So
 * every stage states which system produced it, and a stage nothing measures says `unavailable` and
 * WHY rather than showing a zero. A zero in a funnel is a measurement: it says nobody added anything
 * to a cart. «لا يوجد مصدر يقيس هذه المرحلة» is a completely different sentence.
 *
 * ## Precedence, where two systems can answer
 *
 * The STORE wins over the pixel wherever the store can answer at all. The store's copy is the
 * merchant's own ledger; the pixel's is an estimate assembled from what the browser allowed. Where
 * only the pixel answers, the stage is `measured` and says so — an estimate is worth having, as long
 * as nobody mistakes it for the ledger.
 *
 * ## Attribution decides which orders belong to this funnel
 *
 * Only orders the resolver could place under a campaign of this project count toward the ROAS of an
 * ad. Orders that could not be placed are still counted in the store totals and reported separately
 * as `unattributed` — dropping them would understate a merchant's revenue, and folding them in would
 * overstate the ads'.
 */
final class StoreFunnelService
{
    /**
     * The nine stages, in the order the brief states them, with the metric keys that can measure each.
     *
     * `ad_keys` are `daily_metrics` keys the ad platforms' pixels report. An empty list means no ad
     * platform reports this stage at all.
     *
     * @var list<array{key:string,ar:string,en:string,ad_keys:list<string>,store:bool}>
     */
    private const STAGES = [
        ['key' => 'impressions', 'ar' => 'الظهور', 'en' => 'Impressions', 'ad_keys' => ['impressions'], 'store' => false],
        ['key' => 'clicks', 'ar' => 'النقرات', 'en' => 'Clicks', 'ad_keys' => ['clicks'], 'store' => false],
        ['key' => 'visits', 'ar' => 'الزيارات', 'en' => 'Visits', 'ad_keys' => ['landing_page_views'], 'store' => false],
        ['key' => 'product_views', 'ar' => 'مشاهدة المنتج', 'en' => 'Product views', 'ad_keys' => ['product_views'], 'store' => false],
        ['key' => 'add_to_cart', 'ar' => 'الإضافة للسلة', 'en' => 'Add to cart', 'ad_keys' => ['add_to_cart'], 'store' => true],
        ['key' => 'checkout_started', 'ar' => 'بدء الدفع', 'en' => 'Checkout started', 'ad_keys' => ['checkout'], 'store' => false],
        ['key' => 'orders', 'ar' => 'الطلبات', 'en' => 'Orders', 'ad_keys' => [], 'store' => true],
        ['key' => 'purchases', 'ar' => 'المشتريات', 'en' => 'Purchases', 'ad_keys' => ['purchases'], 'store' => true],
        ['key' => 'revenue', 'ar' => 'الإيرادات', 'en' => 'Revenue', 'ad_keys' => ['revenue'], 'store' => true],
    ];

    /** Order statuses both providers use for an order that is genuinely paid for. */
    private const PAID_STATUSES = ['completed', 'delivered', 'shipped', 'paid', 'fulfilled', 'ready'];

    public function __construct(private readonly ProjectStores $projectStores) {}

    /**
     * @return array<string,mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        /*
         * The window covers whole DAYS, whoever asked and whatever time they put on it.
         *
         * Orders are timestamps; `daily_metrics` rows are dates. A caller that hands in a `to` of
         * midnight — which is what a date picker means by «today», and what the metrics endpoints have
         * always passed — matches every metric row for today and not one order placed since. The
         * dashboard's store block and the analytics tab were reading the same service, over what looked
         * like the same window, and disagreeing about today's revenue by every order of the day.
         *
         * Normalising here rather than at each caller is the point: the service owns what its window
         * means, so a fifth caller cannot get it subtly wrong in a sixth way.
         */
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        /*
         * The stores of THIS PROJECT, not of the tenant (UNIFIED-001).
         *
         * This used to take every store in the tenant, so an agency running two clients out of two
         * projects saw, on either project's funnel, the other client's shop counted in
         * `coverage.stores` and named in `stores_without_cart_data` — a store name crossing a project
         * boundary, and a cart-completeness verdict decided by a shop the reader has nothing to do
         * with. The orders were project-scoped throughout, so the figures were right and everything
         * said ABOUT them was wrong, which is the harder kind to spot.
         */
        $stores = $this->projectStores->forProject($tenantId, $projectId);
        $pendingStores = $stores->isEmpty() ? $this->projectStores->unsynced($tenantId) : collect();

        $orders = CommerceOrder::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('placed_at', [$from, $to])
            ->get();

        $store = $this->storeMeasurements($tenantId, $projectId, $orders, $stores, $from, $to);
        $ads = $this->adMeasurements($projectId, $from, $to);

        $stages = [];

        foreach (self::STAGES as $stage) {
            $stages[] = $this->stage($stage, $store, $ads, $stores);
        }

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stages' => $stages,
            'steps' => $this->steps($stages),
            'totals' => [
                'spend' => $ads['spend'],
                'revenue' => $store['revenue'],
                'gross_revenue' => $store['gross_revenue'],
                'refunded' => $store['refunded'],
                'cancelled_orders' => $store['cancelled_orders'],
                'orders' => $store['orders'],
                'new_customers' => $store['new_customers'],
                'attributed_orders' => $store['attributed_orders'],
                'attributed_revenue' => $store['attributed_revenue'],
                'unattributed_orders' => $store['orders'] - $store['attributed_orders'],
            ],
            'derived' => $this->derived($ads, $store),
            'comparisons' => [
                'platforms' => $this->byPlatform($projectId, $orders, $from, $to),
                'campaigns' => $this->byCampaign($orders),
                'products' => $this->byProduct($orders),
            ],
            'coverage' => $this->coverage($stores, $pendingStores, $orders),
        ];
    }

    /**
     * What the STORE can state, from the merchant's own ledger.
     *
     * @param  Collection<int,CommerceOrder>  $orders
     * @param  Collection<int,ExternalAccount>  $stores
     * @return array<string,mixed>
     */
    private function storeMeasurements(string $tenantId, string $projectId, Collection $orders, Collection $stores, Carbon $from, Carbon $to): array
    {
        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);
        $paid = $live->filter(fn (CommerceOrder $o) => in_array(strtolower((string) $o->status), self::PAID_STATUSES, true));

        /*
         * Carts, only from the providers that report them.
         *
         * A store on a platform with no abandoned-cart endpoint contributes its ORDERS to this stage
         * and nothing else, which understates it — so the stage is marked `partial` rather than
         * `measured` whenever such a store is present. Reporting it as complete would show a checkout
         * rate close to a hundred percent for a merchant losing carts every day.
         */
        $abandoned = CommerceAbandonedCart::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('abandoned_at', [$from, $to])
            ->count();

        $newCustomers = CommerceCustomer::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('first_seen_at', [$from, $to])
            ->count();

        return [
            'has_store' => $stores->isNotEmpty(),
            'orders' => $live->count(),
            'cancelled_orders' => $orders->count() - $live->count(),
            'purchases' => $paid->count(),
            'add_to_cart' => $live->count() + $abandoned,
            'abandoned_carts' => $abandoned,
            'carts_complete' => $stores->every(fn (ExternalAccount $s) => $s->provider !== 'zid'),
            'gross_revenue' => round((float) $live->sum(fn (CommerceOrder $o) => (float) $o->total), 2),
            'revenue' => round((float) $live->sum(fn (CommerceOrder $o) => $o->netRevenue()), 2),
            'refunded' => round((float) $orders->sum(fn (CommerceOrder $o) => (float) $o->refunded_total), 2),
            'new_customers' => $newCustomers,
            'attributed_orders' => $live->whereNotNull('external_campaign_id')->count(),
            'attributed_revenue' => round(
                (float) $live->whereNotNull('external_campaign_id')->sum(fn (CommerceOrder $o) => $o->netRevenue()),
                2,
            ),
        ];
    }

    /**
     * What the AD PLATFORMS reported, pixel and all.
     *
     * @return array<string,float>
     */
    private function adMeasurements(string $projectId, Carbon $from, Carbon $to): array
    {
        $rows = DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('metric_key, SUM(value) AS total')
            ->groupBy('metric_key')
            ->pluck('total', 'metric_key');

        $totals = [];

        foreach ($rows as $key => $total) {
            $totals[(string) $key] = (float) $total;
        }

        $totals['spend'] ??= 0.0;

        return $totals;
    }

    /**
     * One stage, with the system that produced it named.
     *
     * @param  array{key:string,ar:string,en:string,ad_keys:list<string>,store:bool}  $stage
     * @param  array<string,mixed>  $store
     * @param  array<string,float>  $ads
     * @param  Collection<int,ExternalAccount>  $stores
     * @return array<string,mixed>
     */
    private function stage(array $stage, array $store, array $ads, Collection $stores): array
    {
        // The merchant's own ledger, wherever it can answer.
        if ($stage['store'] && $store['has_store']) {
            $value = match ($stage['key']) {
                'add_to_cart' => $store['add_to_cart'],
                'orders' => $store['orders'],
                'purchases' => $store['purchases'],
                'revenue' => $store['revenue'],
                default => null,
            };

            if ($value !== null) {
                $partial = $stage['key'] === 'add_to_cart' && ! $store['carts_complete'];

                return $this->measured($stage, (float) $value, 'stores', $partial ? [
                    'ar' => 'أحد المتاجر على منصة لا تُتيح السلات المتروكة، لذلك هذا الرقم أقل من الحقيقي.',
                    'en' => 'One store is on a platform that does not expose abandoned carts, so this is an undercount.',
                ] : null, $partial);
            }
        }

        // Otherwise whatever the platforms' own reporting carries.
        foreach ($stage['ad_keys'] as $key) {
            if (($ads[$key] ?? 0.0) > 0) {
                return $this->measured($stage, (float) $ads[$key], 'ad_platforms');
            }
        }

        return [
            'key' => $stage['key'],
            'label_ar' => $stage['ar'],
            'label_en' => $stage['en'],
            'value' => null,
            'state' => 'unavailable',
            'source' => ['kind' => 'none', ...$this->whyUnavailable($stage['key'], $store['has_store'])],
        ];
    }

    /**
     * @param  array{key:string,ar:string,en:string,ad_keys:list<string>,store:bool}  $stage
     * @param  array{ar:string,en:string}|null  $note
     * @return array<string,mixed>
     */
    private function measured(array $stage, float $value, string $kind, ?array $note = null, bool $partial = false): array
    {
        return [
            'key' => $stage['key'],
            'label_ar' => $stage['ar'],
            'label_en' => $stage['en'],
            'value' => round($value, 2),
            'state' => $partial ? 'partial' : 'measured',
            'source' => [
                'kind' => $kind,
                'ar' => $kind === 'stores'
                    ? 'من المتجر المرتبط — سجل التاجر نفسه.'
                    : 'من تقارير المنصات الإعلانية — قياس عبر البكسل، وقد يقل عن الواقع.',
                'en' => $kind === 'stores'
                    ? 'From the connected store — the merchant’s own ledger.'
                    : 'From the ad platforms’ own reporting — a pixel measurement, which can undercount.',
            ],
            'note_ar' => $note['ar'] ?? null,
            'note_en' => $note['en'] ?? null,
        ];
    }

    /**
     * Why a stage has no number — stated, because «0» and «nothing measures this» are different facts.
     *
     * @return array{ar:string,en:string}
     */
    private function whyUnavailable(string $key, bool $hasStore): array
    {
        return match ($key) {
            'visits' => [
                'ar' => 'لا يوجد تكامل تحليلات ويب مربوط، والمنصات لم تُبلّغ عن زيارات صفحة الهبوط.',
                'en' => 'No web-analytics integration is connected, and the platforms reported no landing-page views.',
            ],
            'product_views' => [
                'ar' => 'لا تُتيح سلة ولا زد عدد مشاهدات المنتج عبر الـ API، ولم تُبلّغ المنصات عنها.',
                'en' => 'Neither Salla nor Zid exposes product views through their API, and the platforms reported none.',
            ],
            'checkout_started' => [
                'ar' => 'لا تُتيح المنصتان حدث بدء الدفع عبر الـ API، ولم يصل من بكسل المنصات الإعلانية.',
                'en' => 'Neither store platform exposes a checkout-started event, and none arrived from the ad pixels.',
            ],
            default => $hasStore
                ? ['ar' => 'لم تصل بيانات لهذه الفترة بعد.', 'en' => 'No data has arrived for this period yet.']
                : ['ar' => 'لا يوجد متجر مربوط بهذا المشروع.', 'en' => 'No store is connected to this project.'],
        };
    }

    /**
     * Conversion and drop-off between CONSECUTIVE MEASURED stages.
     *
     * A step that spans an unmeasured stage is stated as spanning it — «من النقرات إلى الطلبات» is a
     * real and useful rate, and pretending the unmeasured stages in between had a value would invent
     * two drop-offs that nobody measured.
     *
     * @param  list<array<string,mixed>>  $stages
     * @return list<array<string,mixed>>
     */
    private function steps(array $stages): array
    {
        $measured = array_values(array_filter($stages, static fn (array $s) => $s['value'] !== null));
        $steps = [];

        for ($i = 1; $i < count($measured); $i++) {
            $previous = $measured[$i - 1];
            $current = $measured[$i];

            // Revenue is money, not a count; a "conversion rate" from purchases to riyals is nonsense.
            if ($current['key'] === 'revenue') {
                continue;
            }

            $rate = $previous['value'] > 0 ? $current['value'] / $previous['value'] : null;

            $steps[] = [
                'from' => $previous['key'],
                'to' => $current['key'],
                'conversion_rate' => $rate === null ? null : round($rate * 100, 2),
                'drop_off' => $rate === null ? null : round(max(0.0, 1 - $rate) * 100, 2),
                // True when stages were skipped, so the reader knows what the rate spans.
                'spans_unmeasured_stages' => $this->distance($stages, $previous['key'], $current['key']) > 1,
            ];
        }

        return $steps;
    }

    /** @param list<array<string,mixed>> $stages */
    private function distance(array $stages, string $from, string $to): int
    {
        $keys = array_column($stages, 'key');

        return (int) array_search($to, $keys, true) - (int) array_search($from, $keys, true);
    }

    /**
     * @param  array<string,float>  $ads
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    private function derived(array $ads, array $store): array
    {
        $spend = (float) ($ads['spend'] ?? 0.0);
        $orders = (int) $store['orders'];
        $revenue = (float) $store['revenue'];
        $newCustomers = (int) $store['new_customers'];

        return [
            // Cost per acquisition — spend over ORDERS.
            'cpa' => $orders > 0 && $spend > 0 ? round($spend / $orders, 2) : null,
            /*
             * Customer acquisition cost — spend over NEW customers, which is not the same as CPA.
             *
             * A returning customer's order is revenue, not an acquisition; dividing spend by all
             * orders and calling it CAC flatters a store with loyal customers into thinking it
             * acquires them for a fraction of what it does.
             */
            'cac' => $newCustomers > 0 && $spend > 0 ? round($spend / $newCustomers, 2) : null,
            'aov' => $orders > 0 ? round($revenue / $orders, 2) : null,
            // ROAS on NET revenue: a refunded order is not a return on anything.
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            // And on the revenue that could actually be traced to a campaign.
            'attributed_roas' => $spend > 0 ? round((float) $store['attributed_revenue'] / $spend, 2) : null,
            'conversion_rate' => ($ads['clicks'] ?? 0) > 0 ? round($orders / (float) $ads['clicks'] * 100, 2) : null,
        ];
    }

    /**
     * Revenue and spend side by side per platform.
     *
     * Spend comes from the platform; revenue comes from orders whose attribution names that platform —
     * either through a resolved campaign or through the click id alone. An order attributed to no
     * platform appears in the `unattributed` row rather than being spread across the others.
     *
     * @param  Collection<int,CommerceOrder>  $orders
     * @return list<array<string,mixed>>
     */
    private function byPlatform(string $projectId, Collection $orders, Carbon $from, Carbon $to): array
    {
        $spendByProvider = DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->where('metric_key', 'spend')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('provider, SUM(value) AS total')
            ->groupBy('provider')
            ->pluck('total', 'provider');

        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);

        $rows = [];

        foreach ($spendByProvider as $provider => $spend) {
            $key = AdPlatforms::canonical((string) $provider);
            $rows[$key] ??= ['platform' => $key, 'spend' => 0.0, 'orders' => 0, 'revenue' => 0.0];
            $rows[$key]['spend'] += (float) $spend;
        }

        foreach ($live as $order) {
            $platform = $order->click_id_provider ?? $order->utm_source;
            $key = $platform === null ? null : AdPlatforms::canonical($platform);

            if ($key === null || ! isset($rows[$key])) {
                // A platform we did not spend on this window is not a row on a spend comparison; the
                // order still counts, in the unattributed line below.
                continue;
            }

            $rows[$key]['orders']++;
            $rows[$key]['revenue'] += $order->netRevenue();
        }

        $rows = array_map(static function (array $row): array {
            $row['revenue'] = round($row['revenue'], 2);
            $row['spend'] = round($row['spend'], 2);
            $row['roas'] = $row['spend'] > 0 ? round($row['revenue'] / $row['spend'], 2) : null;

            return $row;
        }, $rows);

        return AdPlatforms::sortRows(array_values($rows), 'platform');
    }

    /**
     * @param  Collection<int,CommerceOrder>  $orders
     * @return list<array<string,mixed>>
     */
    private function byCampaign(Collection $orders): array
    {
        return $orders
            ->filter(fn (CommerceOrder $o) => $o->cancelled_at === null && $o->external_campaign_id !== null)
            ->groupBy('external_campaign_id')
            ->map(fn (Collection $group) => [
                'external_campaign_id' => (string) $group->first()->external_campaign_id,
                'unified_campaign_id' => $group->first()->unified_campaign_id,
                'orders' => $group->count(),
                'revenue' => round((float) $group->sum(fn (CommerceOrder $o) => $o->netRevenue()), 2),
                'attribution_method' => $group->first()->attribution_method,
            ])
            ->sortByDesc('revenue')
            ->values()
            ->all();
    }

    /**
     * Best-selling products by revenue.
     *
     * Read from the order LINES rather than from the products table, because a product's importance is
     * what it sold in this window — not its catalogue price.
     *
     * @param  Collection<int,CommerceOrder>  $orders
     * @return list<array<string,mixed>>
     */
    private function byProduct(Collection $orders): array
    {
        $orderIds = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null)->pluck('id');

        if ($orderIds->isEmpty()) {
            return [];
        }

        return CommerceOrderItem::withoutGlobalScopes()
            ->whereIn('commerce_order_id', $orderIds)
            ->selectRaw('name, SUM(quantity) AS quantity, SUM(total) AS revenue')
            ->groupBy('name')
            ->orderByDesc('revenue')
            ->limit(20)
            ->get()
            ->map(static fn ($row) => [
                'name' => $row->name,
                'quantity' => round((float) $row->quantity, 2),
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->all();
    }

    /**
     * What this funnel could and could not see — the honest footer of the section.
     *
     * @param  Collection<int,ExternalAccount>  $stores
     * @param  Collection<int,ExternalAccount>  $pending  connected to the tenant, not yet swept anywhere
     * @param  Collection<int,CommerceOrder>  $orders
     * @return array<string,mixed>
     */
    private function coverage(Collection $stores, Collection $pending, Collection $orders): array
    {
        return [
            'stores' => $stores->count(),
            /*
             * A shop that is connected but has never been read is reported as its own fact.
             *
             * «لا يوجد متجر مربوط» and «المتجر مربوط ولم تصل بياناته بعد» call for different actions —
             * the first says go and connect one, the second says wait or go and look at why the sweep
             * has not run — and a funnel that could only say the first sent operators to reconnect a
             * store that was already connected. Which project such a store will file under is not
             * knowable until its first sweep decides, so it is not counted as this project's.
             */
            'stores_pending_first_sync' => $pending
                ->map(fn (ExternalAccount $s) => ['id' => $s->getKey(), 'name' => $s->name, 'provider' => $s->provider])
                ->values()
                ->all(),
            'stores_without_cart_data' => $stores
                ->filter(fn (ExternalAccount $s) => $s->provider === 'zid')
                ->map(fn (ExternalAccount $s) => ['id' => $s->getKey(), 'name' => $s->name, 'provider' => $s->provider])
                ->values()
                ->all(),
            'store_last_synced_at' => $stores->max('last_synced_at')?->toIso8601String(),
            'orders_in_window' => $orders->count(),
            /*
             * How many orders carried no usable attribution.
             *
             * Shown rather than hidden: an agency whose orders are 80% unattributed has a tracking
             * problem worth more than any number on this page, and a funnel that quietly folded those
             * orders into the campaigns would hide exactly that.
             */
            'orders_without_attribution' => $orders
                ->filter(fn (CommerceOrder $o) => $o->attribution_method === 'none' || $o->attribution_method === null)
                ->count(),
        ];
    }
}
