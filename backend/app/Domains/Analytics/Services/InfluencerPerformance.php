<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\InfluencerProjectLink;
use App\Domains\Analytics\Support\Source;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\ShortLinks\Models\ShortLinkHop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ANALYTICS-DATA-001 §6 — what each influencer's own link did, and how that sits beside the paid channels.
 *
 * Per influencer, each figure from the source that can state it:
 *
 *  - CLICKS   — the short-link redirect's own counter, `short_link_counter`. A click is not a visit.
 *  - VISITS   — GA4 sessions whose campaign is the link's fixed `utm_campaign`, `ga4`; null when no property is bound.
 *  - ORDERS / REVENUE — store orders carrying the link's `utm_campaign`, or the link's coupon, `order_evidence`. An
 *    order carrying both is ONE order. Cancelled orders are not orders; an order whose money cannot be stated (no
 *    trustworthy rate) is counted and the revenue says so in `revenue_withheld_orders`.
 *  - COST     — the collaboration's creator fee, only when the reader may see costs and a collaboration is linked.
 *  - RETURN   — revenue ÷ cost, only when both are in one currency; otherwise null with the reason.
 *
 * The comparison sets every influencer and every paid platform side by side on the same four figures (spend or cost,
 * store-confirmed orders, store-confirmed revenue, return). A platform's own claim sits beside, never inside.
 */
final class InfluencerPerformance
{
    public function __construct(
        private readonly ProjectOrders $orders,
        private readonly ProjectStores $stores,
        private readonly ReportingTimezone $timezones,
        private readonly Ga4SiteAnalytics $site,
        private readonly AttributionTransparency $transparency,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $links = InfluencerProjectLink::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->orderBy('created_at')->orderBy('id')->get();

        $base = [
            'section' => 'influencers',
            'source' => Source::ORDER_EVIDENCE,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'blended_with_other_sources' => false,
        ];

        if ($links->isEmpty()) {
            return $base + ['state' => 'no_links', 'influencers' => [], 'comparison' => [], 'totals' => null];
        }

        $window = $this->timezones->window($projectId, $from, $to);
        $shorts = ShortLink::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->whereIn('id', $links->pluck('short_link_id')->all())->get()->keyBy(fn (ShortLink $s): string => (string) $s->getKey());
        $hops = ShortLinkHop::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('short_link_id', $shorts->keys()->all())
            ->whereBetween('occurred_at', [$window['from'], $window['to']])
            ->selectRaw('short_link_id, count(*) as follows')->groupBy('short_link_id')->pluck('follows', 'short_link_id');
        $collabs = InfluencerCollaboration::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereIn('id', $links->pluck('collaboration_id')->filter()->all())->get()->keyBy(fn ($c): string => (string) $c->getKey());

        $hasStore = $this->stores->forProject($tenantId, $projectId)->isNotEmpty();
        $live = collect($this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders'])
            ->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null)->values();
        $ga4 = $this->ga4Visits($tenantId, $projectId, $from, $to, $links->pluck('utm_campaign')->all());

        // Each live order to at most one link: the fixed campaign first (it is unique), the coupon second.
        $byCampaign = $links->keyBy(fn (InfluencerProjectLink $l): string => mb_strtolower($l->utm_campaign));
        $byCoupon = $links->filter(fn (InfluencerProjectLink $l): bool => $l->coupon_code !== null)->keyBy(fn (InfluencerProjectLink $l): string => mb_strtolower((string) $l->coupon_code));
        $tied = [];
        foreach ($live as $order) {
            $campaign = mb_strtolower(trim((string) $order->utm_campaign));
            $coupon = mb_strtolower(trim((string) $order->coupon_code));
            $viaCampaign = $campaign !== '' ? $byCampaign->get($campaign) : null;
            $viaCoupon = $coupon !== '' ? $byCoupon->get($coupon) : null;
            $link = $viaCampaign ?? $viaCoupon;
            if ($link === null) {
                continue;
            }
            $tied[(string) $link->getKey()][] = [
                'order' => $order,
                'evidence' => array_values(array_filter([
                    $viaCampaign !== null ? 'utm_campaign' : null,
                    $viaCoupon !== null && (string) $viaCoupon->getKey() === (string) $link->getKey() ? 'coupon' : null,
                ])),
            ];
        }

        $currency = $live->first()?->currency;
        $rows = [];
        foreach ($links as $link) {
            $short = $shorts->get((string) $link->short_link_id);
            $own = $tied[(string) $link->getKey()] ?? [];
            $orders = count($own);
            $confirmed = 0;
            $revenue = 0.0;
            $withheld = 0;
            foreach ($own as $t) {
                $o = $t['order'];
                $confirmed += in_array(strtolower((string) $o->status), StoreFunnelService::PAID_STATUSES, true) ? 1 : 0;
                $net = $o->netRevenue();
                $net === null ? $withheld++ : $revenue += $net;
            }
            $revenue = round($revenue, 2);
            $collab = $link->collaboration_id === null ? null : $collabs->get((string) $link->collaboration_id);
            $visits = $ga4 === null ? null : ($ga4[mb_strtolower($link->utm_campaign)] ?? ['sessions' => 0.0, 'add_to_carts' => 0.0, 'checkouts' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0]);

            $rows[] = [
                'id' => (string) $link->getKey(),
                'name' => $link->name,
                'influencer_id' => $link->influencer_id,
                'is_active' => $link->is_active,
                'link' => [
                    'short_link_id' => (string) $link->short_link_id,
                    'slug' => $short?->slug,
                    'state' => $short?->state(),
                    'utm' => ['source' => $link->utm_source, 'medium' => $link->utm_medium, 'campaign' => $link->utm_campaign],
                    'coupon_code' => $link->coupon_code,
                ],
                'clicks' => Source::of((int) ($hops[(string) $link->short_link_id] ?? 0), Source::SHORT_LINK, ['all_time' => ($short === null ? 0 : (int) $short->clicks), 'basis' => 'recorded_in_window']),
                'visits' => $visits === null ? Source::of(null, Source::GA4, ['reason' => 'no_ga4_property']) : Source::of(round($visits['sessions'], 2), Source::GA4, ['add_to_carts' => $visits['add_to_carts'], 'checkouts' => $visits['checkouts'], 'purchases' => $visits['purchases']]),
                'orders' => $hasStore ? Source::of($orders, Source::ORDER_EVIDENCE, ['confirmed' => $confirmed, 'evidence' => $this->evidenceCounts($own)]) : Source::of(null, Source::ORDER_EVIDENCE, ['reason' => 'no_store']),
                'revenue' => $hasStore ? Source::of($revenue, Source::ORDER_EVIDENCE, ['currency' => $currency, 'revenue_withheld_orders' => $withheld]) : Source::of(null, Source::ORDER_EVIDENCE, ['reason' => 'no_store']),
                'collaboration_id' => $collab === null ? null : (string) $collab->getKey(),
                // Cost is filled by the reader-specific cut; a null here is «not stated», never zero.
                'cost' => $collab === null || $collab->influencer_fee === null
                    ? Source::of(null, Source::COLLABORATION, ['reason' => $collab === null ? 'no_collaboration' : 'no_fee'])
                    : Source::of(round((float) $collab->influencer_fee, 2), Source::COLLABORATION, ['currency' => (string) $collab->currency]),
            ];
        }

        $rows = array_map(fn (array $r): array => $r + $this->returns($r, $currency), $rows);

        return $base + [
            'state' => 'ready',
            'currency' => $currency,
            'has_store' => $hasStore,
            'ga4_state' => $ga4 === null ? 'no_ga4_property' : 'ga4',
            'influencers' => $rows,
            'comparison' => $this->comparison($tenantId, $projectId, $from, $to, $rows, $currency),
            'totals' => [
                'influencers' => count($rows),
                'clicks' => Source::of(array_sum(array_map(static fn (array $r): int => $r['clicks']['value'], $rows)), Source::SHORT_LINK),
                'orders' => $hasStore ? Source::of(array_sum(array_map(static fn (array $r): int => (int) $r['orders']['value'], $rows)), Source::ORDER_EVIDENCE) : Source::of(null, Source::ORDER_EVIDENCE),
            ],
        ];
    }

    /**
     * @param  list<array{order: CommerceOrder, evidence: list<string>}>  $own
     * @return array<string, int>
     */
    private function evidenceCounts(array $own): array
    {
        $out = ['utm_campaign' => 0, 'coupon' => 0];
        foreach ($own as $t) {
            foreach ($t['evidence'] as $e) {
                $out[$e]++;
            }
        }

        return $out;
    }

    /**
     * Return on cost: only when the orders' currency and the fee's currency are the same one.
     *
     * @param  array<string, mixed>  $r
     * @return array{roas: ?float, roi: ?float, cpa: ?float, return_basis: array<string, mixed>}
     */
    private function returns(array $r, ?string $currency): array
    {
        $cost = $r['cost']['value'];
        $revenue = $r['revenue']['value'];
        $orders = $r['orders']['value'];
        $same = $cost !== null && $currency !== null && ($r['cost']['currency'] ?? null) === $currency;
        $basis = ['source' => 'derived', 'from' => ['revenue' => Source::ORDER_EVIDENCE, 'cost' => Source::COLLABORATION]];
        if ($cost === null) {
            return ['roas' => null, 'roi' => null, 'cpa' => null, 'return_basis' => $basis + ['reason' => 'no_cost']];
        }
        if (! $same) {
            return ['roas' => null, 'roi' => null, 'cpa' => null, 'return_basis' => $basis + ['reason' => 'currency_differs']];
        }

        return [
            'roas' => $cost > 0 && $revenue !== null ? round($revenue / $cost, 2) : null,
            'roi' => $cost > 0 && $revenue !== null ? round(($revenue - $cost) / $cost, 4) : null,
            'cpa' => $cost > 0 && $orders > 0 ? round($cost / $orders, 2) : null,
            'return_basis' => $basis + ['reason' => null],
        ];
    }

    /**
     * GA4 sessions per fixed campaign value; null when the project has no bound property.
     *
     * @param  list<string>  $campaigns
     * @return array<string, array<string, float>>|null
     */
    private function ga4Visits(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $campaigns): ?array
    {
        $property = $this->site->property($tenantId, $projectId);
        if ($property === null) {
            return null;
        }

        $rows = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $property->getKey())
            ->where('breakdown', 'campaign')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn(DB::raw('lower(dimension_1)'), array_map('mb_strtolower', $campaigns))
            ->get(['dimension_1', 'metrics']);

        $out = [];
        foreach ($rows as $r) {
            $m = (array) json_decode((string) $r->metrics, true);
            $key = mb_strtolower((string) $r->dimension_1);
            $out[$key] ??= ['sessions' => 0.0, 'add_to_carts' => 0.0, 'checkouts' => 0.0, 'purchases' => 0.0, 'revenue' => 0.0];
            foreach (array_keys($out[$key]) as $k) {
                $out[$key][$k] += (float) ($m[$k] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Influencers and paid platforms on the same four figures.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function comparison(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $rows, ?string $currency): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'kind' => 'influencer', 'channel' => $r['name'], 'id' => $r['id'],
                'spend' => $r['cost'],
                'orders' => $r['orders'],
                'revenue' => $r['revenue'],
                'roas' => $r['roas'],
                'cpa' => $r['cpa'],
                'source' => Source::ORDER_EVIDENCE,
            ];
        }

        $spend = collect(app(MetricsAggregator::class)->forProjects([$projectId])->byProvider($from, $to))->keyBy('provider');
        $spendCurrency = app(MetricsAggregator::class)->forProjects([$projectId])->currencyBasis($from, $to)['currency'];
        $three = $this->transparency->threeSources($tenantId, $projectId, $from, $to);
        $storeCurrency = $three['store_currency'];
        $comparable = $spendCurrency !== null && $storeCurrency !== null && $spendCurrency === $storeCurrency;

        foreach ($three['rows'] as $t) {
            $platform = (string) $t['platform'];
            $s = $spend->get($platform);
            $spent = $s === null ? null : round((float) $s['spend'], 2);
            $storeOrders = $t['store_confirmed']['orders'] ?? null;
            $storeRevenue = $t['store_confirmed']['revenue'] ?? null;
            $out[] = [
                'kind' => 'platform', 'channel' => $platform, 'id' => $platform,
                'spend' => Source::of($spent, Source::PLATFORM_SPEND, ['currency' => $spendCurrency]),
                'orders' => Source::of($storeOrders, Source::STORE_CONFIRMED, ['platform_reported' => $t['platform_reported']['orders'] ?? null]),
                'revenue' => Source::of($storeRevenue, Source::STORE_CONFIRMED, ['currency' => $storeCurrency]),
                'roas' => $comparable && $spent !== null && $spent > 0 && $storeRevenue !== null ? round($storeRevenue / $spent, 2) : null,
                'cpa' => $spent !== null && $spent > 0 && ($storeOrders ?? 0) > 0 ? round($spent / $storeOrders, 2) : null,
                'source' => Source::STORE_CONFIRMED,
            ];
        }

        return $out;
    }

    /**
     * The payload for a reader who may not see costs: every cost cell and every figure derived from one is withheld,
     * and says so. A cut made AFTER the cache, so one reader's permission is never served to another.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withoutCosts(array $payload): array
    {
        $hidden = Source::of(null, Source::COLLABORATION, ['reason' => 'hidden']);
        foreach ($payload['influencers'] ?? [] as $i => $r) {
            $payload['influencers'][$i]['cost'] = $hidden;
            $payload['influencers'][$i]['roas'] = $payload['influencers'][$i]['roi'] = $payload['influencers'][$i]['cpa'] = null;
            $payload['influencers'][$i]['return_basis'] = ['source' => 'derived', 'reason' => 'hidden'];
        }
        foreach ($payload['comparison'] ?? [] as $i => $r) {
            if ($r['kind'] === 'influencer') {
                $payload['comparison'][$i]['spend'] = $hidden;
                $payload['comparison'][$i]['roas'] = $payload['comparison'][$i]['cpa'] = null;
            }
        }

        return $payload;
    }
}
