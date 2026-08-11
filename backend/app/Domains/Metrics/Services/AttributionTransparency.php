<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Metrics\Models\DailyMetric;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * REPORT-OBJECTIVE-005 — «كم بعنا؟», answered by naming who is answering.
 *
 * ## Two measurements, never one number
 *
 * A purchase is reported by two kinds of system that do not agree and are not supposed to.
 *
 *   - **Platform-Reported** — each ad platform's own count of the conversions IT believes its ads
 *     caused, under ITS attribution window, from ITS pixel. It is an estimate, it is the only number
 *     available before a store is connected, and it is the number the platform optimises against.
 *   - **Store-Confirmed** — the merchant's own ledger. One row per sale, with an order id.
 *
 * They are labelled separately and they stay separate. Printing one figure called «الطلبات» when two
 * systems answered differently forces the reader to guess which they are reading, and they will
 * guess the flattering one.
 *
 * ## Why there is no unified platform total
 *
 * `total_orders` on the platform-reported block is **null**, and the reason is stated in the payload.
 *
 * One sale that a shopper clicked from Snapchat on Tuesday and from Meta on Thursday is reported by
 * BOTH platforms, in full, as one conversion each. This is not a bug in either — each is answering
 * «did my ad contribute?» and each is right. Adding them answers a question nobody asked and prints
 * two orders where one sale happened.
 *
 * Deduplicating them would need a key both platforms carry and neither does: conversion payloads
 * name no order id, and no platform among the six exposes a click-to-order lookup for this purpose.
 * So the sum is not computed and not offered. «مجموع تقديرات المنصات» would still be read as a
 * number of orders by everyone who saw it, whatever it was labelled.
 *
 * The contract states it directly: without reliable dedup, per-platform figures are shown separately
 * and labelled `Platform-Reported` — never summed into unique unified orders.
 *
 * ## What CAN be totalled, and why
 *
 * The store's. An order id is a real dedup key, so store-confirmed orders carry `dedup: exact` and a
 * total that means what it says — after {@see ProjectOrders} removes the copies a shop connected
 * twice produces, which is the only duplicate this system can prove and therefore the only one it
 * removes.
 *
 * ## The per-platform comparison, which is the useful part
 *
 * For each platform, its own claim sits beside the orders the STORE recorded and attribution placed
 * on that platform. Those two are comparable — same platform, same window, same project — and the
 * gap between them is the single most actionable number on the page: it is that platform's
 * over-claim, in orders. It is stated as a difference and never as a correction; neither figure is
 * adjusted to match the other, because we do not know which is wrong.
 *
 * A platform with no store connected gets `store_confirmed_orders: null`, not `0`. Zero says the
 * platform claimed sales the shop never saw. Null says nobody checked.
 *
 * ## Click-through and view-through
 *
 * Read out of the attribution window the rows were collected under (`7d_click_1d_view` → 7 and 1),
 * never assumed. A window of `default` means the platform did not tell us, and both figures are null
 * with `window_known: false` — a defaulted «7 days» would be a claim about a client's data invented
 * by this file.
 */
final class AttributionTransparency
{
    /**
     * The one metric key that means «an order», matching {@see ObjectivePerformance} and the
     * dashboard exactly.
     *
     * `purchases` is stored too, and is NOT added to this. Summing both double-counts on any
     * integration that reports one sale under both keys, and — worse — would make this page disagree
     * with the CPA printed everywhere else in the product. One definition applied everywhere beats a
     * better definition applied here.
     */
    private const ORDERS_KEY = 'conversions';

    private const REVENUE_KEY = 'revenue';

    public function __construct(
        private readonly ProjectOrders $projectOrders,
        private readonly ProjectStores $projectStores,
        private readonly ReportingTimezone $timezones,
    ) {}

    /**
     * @param  list<string>  $providers  empty = every platform
     * @return array<string,mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $providers = []): array
    {
        /*
         * COMMERCE-TZ-001 — the same window the funnel uses, measured on the same clock.
         *
         * This report and the funnel are read side by side and are expected to agree. Two services
         * each doing their own `startOfDay()` in the server's zone agreed only by coincidence, and
         * would have stopped agreeing the moment one of them was localised and the other was not.
         */
        $window = $this->timezones->window($projectId, $from, $to);
        $from = $window['from'];
        $to = $window['to'];

        $loaded = $this->projectOrders->forWindow($tenantId, $projectId, $from, $to);
        $hasStore = $this->projectStores->forProject($tenantId, $projectId)->isNotEmpty();

        $platformRows = $this->platformRows($projectId, $window['from_date'], $window['to_date'], $providers);
        $storeByPlatform = $hasStore ? $this->storeOrdersByPlatform($loaded['orders']) : [];

        $platforms = [];

        foreach ($platformRows as $provider => $row) {
            $confirmed = $storeByPlatform[$provider] ?? null;

            $platforms[] = [
                'provider' => $provider,
                'platform_reported_orders' => round($row['orders'], 2),
                'platform_reported_revenue' => round($row['revenue'], 2),
                // Null, never 0, when there is no ledger to check against — see the class docblock.
                'store_confirmed_orders' => $hasStore ? ($confirmed['orders'] ?? 0) : null,
                'store_confirmed_revenue' => $hasStore ? round($confirmed['revenue'] ?? 0.0, 2) : null,
                'difference' => $hasStore ? round($row['orders'] - ($confirmed['orders'] ?? 0), 2) : null,
                'ratio' => $hasStore && ($confirmed['orders'] ?? 0) > 0
                    ? round($row['orders'] / $confirmed['orders'], 3)
                    : null,
                'attribution' => $this->attributionOf($row['windows']),
                'currency' => $row['currency'],
            ];
        }

        // PLATFORM-ORDER-001 — the product's order, not «whoever claimed most». A list that reorders
        // itself as the figures move makes a reader hunt for the same platform every time they look.
        $platforms = AdPlatforms::sortRows($platforms, 'provider');

        return [
            // The client's own dates — see ReportingTimezone::window().
            'period' => ['from' => $window['from_date'], 'to' => $window['to_date'], 'timezone' => $window['timezone']],
            'platform_reported' => $this->platformReported($platforms),
            'store_confirmed' => $this->storeConfirmed($hasStore, $loaded),
            'dedup' => $this->dedup($hasStore, $platforms, $loaded),
            'models' => $this->models($projectId, $window['from_date'], $window['to_date']),
            'unattributed' => $this->unattributed($hasStore, $loaded['orders']),
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $platforms
     * @return array<string,mixed>
     */
    private function platformReported(array $platforms): array
    {
        return [
            'label_ar' => 'ما أبلغت به المنصات',
            'label_en' => 'Platform-Reported',
            'basis_ar' => 'كل منصة تحسب التحويلات التي تعتقد أن إعلانها تسبب بها، وفق نافذة الإسناد الخاصة بها.',
            'basis_en' => "Each platform's own count of the conversions it believes its ads caused, under its own attribution window.",
            'platforms' => $platforms,
            /*
             * Null on purpose, and the reason travels with it so no surface has to remember the rule.
             * A UI that received a number here would print it, whatever the label said.
             */
            'total_orders' => null,
            'total_revenue' => null,
            'total_withheld' => true,
            'total_withheld_reason' => 'no_shared_order_key_across_platforms',
            'total_withheld_ar' => 'لا يوجد مفتاح مشترك يربط تحويل منصة بتحويل منصة أخرى، فالبيعة الواحدة قد تُبلَّغ من أكثر من منصة. الجمع ينتج عدد طلبات غير حقيقي، ولذلك لا يُحسب.',
            'total_withheld_en' => 'No shared key links one platform’s conversion to another’s, so a single sale can be reported by more than one platform. Summing produces an order count that never happened, so it is not computed.',
        ];
    }

    /**
     * @param  array{orders:Collection<int,CommerceOrder>,duplicates_collapsed:int,duplicated_shops:list<array<string,mixed>>}  $loaded
     * @return array<string,mixed>
     */
    private function storeConfirmed(bool $hasStore, array $loaded): array
    {
        if (! $hasStore) {
            return [
                'label_ar' => 'ما أكّده المتجر',
                'label_en' => 'Store-Confirmed',
                'available' => false,
                'unavailable_reason' => 'no_store_connected',
                'unavailable_ar' => 'لا يوجد متجر مربوط بهذا المشروع، فلا يوجد سجل مبيعات يمكن التأكد منه.',
                'unavailable_en' => 'No store is connected to this project, so there is no ledger to confirm against.',
                'orders' => null,
                'revenue' => null,
                'currency' => null,
            ];
        }

        /** @var Collection<int,CommerceOrder> $live */
        $live = $loaded['orders']->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);

        return [
            'label_ar' => 'ما أكّده المتجر',
            'label_en' => 'Store-Confirmed',
            'available' => true,
            'unavailable_reason' => null,
            'basis_ar' => 'دفتر التاجر نفسه: صف واحد لكل بيعة، لكل منها رقم طلب.',
            'basis_en' => "The merchant's own ledger: one row per sale, each with an order id.",
            'orders' => $live->count(),
            'revenue' => round((float) $live->sum(fn (CommerceOrder $o) => $o->netRevenue() ?? 0.0), 2),
            /*
             * The REPORTING currency, which since COMMERCE-FX-001 is what every order row is stated
             * in — so reading it off the first order is now a fact rather than the guess it used to
             * be. Before the conversion existed this line named one shop's currency and applied it to
             * a sum that could contain several.
             */
            'currency' => $live->first()?->currency,
            // Orders whose conversion could not be vouched for, and are therefore missing from the
            // revenue above. Stated here so «مؤكَّد من المتجر» is never quietly incomplete.
            'orders_with_money_withheld' => $live->filter(fn (CommerceOrder $o) => $o->moneyWithheld())->count(),
            'cancelled_orders' => $loaded['orders']->count() - $live->count(),
            'attributed_orders' => $live->whereNotNull('external_campaign_id')->count(),
            'duplicates_collapsed' => $loaded['duplicates_collapsed'],
            'shops_connected_more_than_once' => $loaded['duplicated_shops'],
        ];
    }

    /**
     * The dedup verdict — separately for each measurement, because they have different answers.
     *
     * @param  list<array<string,mixed>>  $platforms
     * @param  array{duplicates_collapsed:int,duplicated_shops:list<array<string,mixed>>}  $loaded
     * @return array<string,mixed>
     */
    private function dedup(bool $hasStore, array $platforms, array $loaded): array
    {
        $comparable = array_values(array_filter(
            $platforms,
            static fn (array $p): bool => $p['store_confirmed_orders'] !== null && $p['store_confirmed_orders'] > 0,
        ));

        return [
            'platform_reported' => [
                'status' => 'not_possible',
                'reason_ar' => 'التحويلات لا تحمل رقم طلب، ولا تتيح أي من المنصات الست ربط نقرة بطلبية. لا يمكن إثبات أن تحويلين من منصتين هما البيعة نفسها.',
                'reason_en' => 'Conversions carry no order id, and none of the six platforms exposes a click-to-order lookup. Two conversions from two platforms cannot be proven to be one sale.',
                'may_be_summed' => false,
            ],
            'store_confirmed' => [
                'status' => $hasStore ? 'exact' : 'unavailable',
                'key' => $hasStore ? 'provider + shop id + order id' : null,
                'reason_ar' => $hasStore
                    ? 'لكل طلبية رقم في المتجر، فالمفتاح حقيقي. النسخ الناتجة عن ربط المتجر أكثر من مرة تُدمج قبل أي عملية حساب.'
                    : 'لا يوجد متجر مربوط.',
                'reason_en' => $hasStore
                    ? 'Every order has a store id, so the key is real. Copies produced by a shop connected more than once are collapsed before any figure is computed.'
                    : 'No store is connected.',
                'may_be_summed' => $hasStore,
                'duplicates_collapsed' => $loaded['duplicates_collapsed'],
            ],
            /*
             * The comparison is per platform and never rolled up.
             *
             * A single «المنصات تبالغ بنسبة 40%» would need the unified platform total this whole
             * class refuses to produce, and it would be the number everybody quotes.
             */
            'comparison_ar' => 'كل منصة تُقارَن بطلبات المتجر التي أُسندت إليها. لا يوجد إجمالي موحّد للمنصات.',
            'comparison_en' => 'Each platform is compared against the store orders attribution placed on it. There is no unified platform total.',
            'comparable_platforms' => count($comparable),
        ];
    }

    /**
     * Attribution windows for one platform, decomposed into click-through and view-through.
     *
     * @param  array<string,int>  $windows  window string => rows collected under it
     * @return array<string,mixed>
     */
    private function attributionOf(array $windows): array
    {
        arsort($windows);
        $names = array_keys($windows);

        $click = null;
        $view = null;
        $known = false;

        foreach ($names as $name) {
            if (preg_match('/(\d+)d[_-]?click/i', $name, $m) === 1) {
                $click = max($click ?? 0, (int) $m[1]);
                $known = true;
            }

            if (preg_match('/(\d+)d[_-]?view/i', $name, $m) === 1) {
                $view = max($view ?? 0, (int) $m[1]);
                $known = true;
            }
        }

        return [
            'windows' => array_map(
                static fn (string $name): array => ['window' => $name, 'rows' => $windows[$name]],
                $names,
            ),
            /*
             * More than one window inside a single platform's figures means those figures were
             * collected under different rules and are not internally comparable. Said out loud rather
             * than resolved by taking the commonest.
             */
            'mixed_windows' => count($names) > 1,
            'window_known' => $known,
            'click_through_days' => $click,
            'view_through_days' => $view,
            'includes_view_through' => $known ? ($view !== null && $view > 0) : null,
            'unknown_ar' => $known ? null : 'لم تُرسل المنصة نافذة إسناد مع هذه الأرقام.',
            'unknown_en' => $known ? null : 'The platform sent no attribution window with these figures.',
        ];
    }

    /**
     * The attribution MODEL, which is governance rather than measurement — it is set on the campaign
     * by whoever runs it, and a campaign with none set says `unset` rather than being given a default.
     *
     * @return list<array<string,mixed>>
     */
    private function models(string $projectId, string $fromDate, string $toDate): array
    {
        $campaignIds = DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('metric_date', [$fromDate, $toDate])
            ->whereNotNull('unified_campaign_id')
            ->distinct()
            ->pluck('unified_campaign_id')
            ->all();

        if ($campaignIds === []) {
            return [];
        }

        return UnifiedCampaign::withoutGlobalScopes()
            ->whereIn('id', $campaignIds)
            ->get(['id', 'name', 'attribution_model', 'attribution_window'])
            ->groupBy(fn (UnifiedCampaign $c) => (string) ($c->attribution_model ?: 'unset'))
            ->map(fn (Collection $group, string $model): array => [
                'model' => $model,
                'is_set' => $model !== 'unset',
                'campaigns' => $group->count(),
                'campaign_names' => $group->take(10)->pluck('name')->values()->all(),
                'windows' => $group
                    ->pluck('attribution_window')
                    ->map(fn ($w) => (string) ($w ?: 'unset'))
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Orders the resolver could not place. Reported, never distributed.
     *
     * @param  Collection<int,CommerceOrder>  $orders
     * @return array<string,mixed>
     */
    private function unattributed(bool $hasStore, Collection $orders): array
    {
        if (! $hasStore) {
            return ['available' => false, 'orders' => null, 'revenue' => null, 'share' => null, 'by_method' => []];
        }

        /** @var Collection<int,CommerceOrder> $live */
        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);
        $none = $live->filter(fn (CommerceOrder $o) => $o->external_campaign_id === null);

        /*
         * WHY each unplaced order could not be placed — grouped over `$none`, not over every order.
         *
         * Grouping the whole set put `utm_campaign_id` at the top of a block headed «طلبات بلا إسناد»,
         * which is a contradiction: those orders WERE placed. Found live, in the payload, not by the
         * suite — the count above it was right the whole time, and only the breakdown lied.
         */
        $byMethod = $none
            ->groupBy(fn (CommerceOrder $o) => (string) ($o->attribution_method ?: 'not_resolved'))
            ->map(fn (Collection $g, string $method): array => ['method' => $method, 'orders' => $g->count()])
            ->sortByDesc('orders')
            ->values()
            ->all();

        return [
            'available' => true,
            'orders' => $none->count(),
            'revenue' => round((float) $none->sum(fn (CommerceOrder $o) => $o->netRevenue() ?? 0.0), 2),
            'share' => $live->count() > 0 ? round($none->count() / $live->count(), 4) : null,
            'by_method' => $byMethod,
            'note_ar' => 'الطلبات غير المسندة تبقى ضمن إجمالي المتجر ولا تُوزَّع على أي حملة.',
            'note_en' => 'Unattributed orders stay in the store total and are distributed to no campaign.',
        ];
    }

    /**
     * Platform-reported figures, one row per provider, with the windows they arrived under.
     *
     * @param  list<string>  $providers
     * @return array<string,array{orders:float,revenue:float,currency:?string,windows:array<string,int>}>
     */
    private function platformRows(string $projectId, string $fromDate, string $toDate, array $providers): array
    {
        $rows = DailyMetric::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('metric_date', [$fromDate, $toDate])
            ->whereIn('metric_key', [self::ORDERS_KEY, self::REVENUE_KEY])
            ->when($providers !== [], fn ($q) => $q->whereIn('provider', $providers))
            ->groupBy('provider', 'metric_key', 'attribution_window', 'project_currency')
            ->select('provider', 'metric_key', 'attribution_window', 'project_currency')
            ->selectRaw('SUM(value) AS total')
            ->selectRaw('COUNT(*) AS rows_count')
            ->toBase()
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $provider = AdPlatforms::canonical((string) $row->provider);
            $out[$provider] ??= ['orders' => 0.0, 'revenue' => 0.0, 'currency' => null, 'windows' => []];

            if ($row->metric_key === self::ORDERS_KEY) {
                $out[$provider]['orders'] += (float) $row->total;
            } else {
                $out[$provider]['revenue'] += (float) $row->total;
                $out[$provider]['currency'] ??= $row->project_currency !== null ? (string) $row->project_currency : null;
            }

            $window = (string) ($row->attribution_window ?: 'default');
            $out[$provider]['windows'][$window] = ($out[$provider]['windows'][$window] ?? 0) + (int) $row->rows_count;
        }

        return $out;
    }

    /**
     * Store orders grouped by the ad platform attribution placed them on.
     *
     * The resolved CAMPAIGN's platform is preferred over the click id's, because a resolved campaign
     * is the stronger statement — it names both the platform and which campaign — and the resolver
     * already refuses to produce one when the two signals disagree.
     *
     * @param  Collection<int,CommerceOrder>  $orders
     * @return array<string,array{orders:int,revenue:float}>
     */
    private function storeOrdersByPlatform(Collection $orders): array
    {
        /** @var Collection<int,CommerceOrder> $live */
        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null);

        $campaignIds = $live->pluck('external_campaign_id')->filter()->unique()->values()->all();

        $campaignProviders = $campaignIds === []
            ? []
            : ExternalCampaign::withoutGlobalScopes()
                ->whereIn('id', $campaignIds)
                ->pluck('provider', 'id')
                ->all();

        $out = [];

        foreach ($live as $order) {
            $raw = $order->external_campaign_id !== null
                ? ($campaignProviders[$order->external_campaign_id] ?? null)
                : $order->click_id_provider;

            if ($raw === null) {
                continue;
            }

            $provider = AdPlatforms::canonical((string) $raw);
            $out[$provider] ??= ['orders' => 0, 'revenue' => 0.0];
            $out[$provider]['orders']++;
            $out[$provider]['revenue'] += $order->netRevenue() ?? 0.0;
        }

        return $out;
    }
}
