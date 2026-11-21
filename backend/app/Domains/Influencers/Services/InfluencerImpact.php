<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Services;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\OrderAttributionResolver;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\StoreFunnelService;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * INFLUENCER-IMPACT-001 — what each creator did, in figures that carry their evidence. Built on the tracking assets the
 * product already issues; there is no second tracking engine here.
 *
 * Reachable only through the `influencers` portal, so it answers nothing while INFL-OFF-001 holds the service closed.
 *
 * What each figure may claim:
 *   - Clicks are counted by our own redirect, from the day the link was issued. The counter holds no dates, so they are
 *     all-time and say so. A click is not a visit, a chat or a sale.
 *   - Orders are the store's, tied by the creator's coupon as the resolver recorded it, in this window. Link clicks have
 *     no order evidence, so nothing is credited from them.
 *   - An order carrying the coupon that the resolver placed on a platform instead is `overlap`, reported and not credited.
 *   - Visits and add-to-cart have no per-creator evidence and are null, with the reason stated.
 *   - CPA, ROAS and ROI appear only where the formula holds: a reader allowed to see costs, a stated cost, one currency.
 */
final class InfluencerImpact
{
    public const PAGE_SIZES = [10, 25, 50];

    public const SORTS = ['name', 'clicks', 'orders', 'confirmed', 'net_revenue', 'overlap_orders', 'cost', 'cpa', 'roas', 'roi'];

    public function __construct(
        private readonly ProjectOrders $orders,
        private readonly OrderAttributionResolver $resolver,
        private readonly ReportingTimezone $timezones,
    ) {}

    /**
     * @param  list<string>|null  $clientIds  the reader's client scope; null means every client
     * @param  array{page?: int, per_page?: int, sort?: string, dir?: string, q?: ?string}  $o
     * @return array<string, mixed>
     */
    public function build(string $tenantId, ?array $clientIds, bool $canSeeCosts, Carbon $from, Carbon $to, array $o, bool $all = false): array
    {
        $collaborations = InfluencerCollaboration::query()
            ->with('influencer')
            ->when($clientIds !== null, static fn ($q) => $q->where(static function ($w) use ($clientIds): void {
                $w->whereNull('client_workspace_id')->orWhereIn('client_workspace_id', $clientIds);
            }))
            ->get();

        $assets = InfluencerTrackingAsset::query()->whereIn('collaboration_id', $collaborations->pluck('id')->all())->get()->groupBy('collaboration_id');
        $ugc = InfluencerDeliverable::query()->whereIn('collaboration_id', $collaborations->pluck('id')->all())->whereRaw('lower(type) like ?', ['%ugc%'])->pluck('collaboration_id')->flip();

        $projectIds = $collaborations->flatMap(fn (InfluencerCollaboration $c): array => $this->resolver->projectIdsOf($c))->unique()->values();
        $ledger = $projectIds->mapWithKeys(fn (string $id): array => [$id => $this->ordersOf($tenantId, $id, $from, $to)]);

        $rows = [];
        foreach ($collaborations as $collaboration) {
            $key = (string) $collaboration->influencer_id;
            /** @var array<string, mixed> $row */
            $row = $rows[$key] ?? [
                'influencer_id' => $key,
                'name' => (string) $collaboration->influencer->name,
                'handle' => $collaboration->influencer->handle,
                'collaborations' => 0, 'links' => 0, 'codes' => 0, 'clicks' => 0, 'clicks_basis' => 'since_issued', 'visits' => null, 'add_to_cart' => null,
                'orders' => 0, 'confirmed' => 0, 'overlap_orders' => 0, 'revenue_by_currency' => [], 'cost_by_currency' => [], 'has_store' => false, 'is_ugc' => false,
                'redemption_sources' => [],
            ];
            $row['collaborations']++;
            $row['is_ugc'] = $row['is_ugc'] || $ugc->has((string) $collaboration->getKey());
            if ($canSeeCosts && $collaboration->influencer_fee !== null) {
                $currency = (string) $collaboration->currency;
                $row['cost_by_currency'][$currency] = ($row['cost_by_currency'][$currency] ?? 0.0) + (float) $collaboration->influencer_fee;
            }

            $own = $this->resolver->projectIdsOf($collaboration);
            $row['has_store'] = $row['has_store'] || $own !== [];
            foreach ($assets[(string) $collaboration->getKey()] ?? [] as $asset) {
                if ($asset->kind === 'link') {
                    $row['links']++;
                    $row['clicks'] += (int) $asset->clicks;

                    continue;
                }
                $row['codes']++;
                $row['redemption_sources'][(string) $asset->redemptions_source] = true;
                $code = mb_strtolower((string) $asset->code);
                foreach ($own as $projectId) {
                    foreach ($ledger[$projectId] ?? [] as $order) {
                        if ($order->cancelled_at !== null || mb_strtolower(trim((string) $order->coupon_code)) !== $code) {
                            continue;
                        }
                        if ($order->attribution_method !== 'influencer_coupon') {
                            $row['overlap_orders']++;

                            continue;
                        }
                        $row['orders']++;
                        $row['confirmed'] += in_array(strtolower((string) $order->status), StoreFunnelService::PAID_STATUSES, true) ? 1 : 0;
                        $currency = (string) $order->currency;
                        $row['revenue_by_currency'][$currency] = ($row['revenue_by_currency'][$currency] ?? 0.0) + ($order->netRevenue() ?? 0.0);
                    }
                }
            }
            $rows[$key] = $row;
        }

        $list = collect(array_values($rows))->map(fn (array $r): array => $this->derive($r, $canSeeCosts));
        $summary = $this->summary($list, $ledger);

        if (($term = mb_strtolower(trim((string) ($o['q'] ?? '')))) !== '') {
            $list = $list->filter(static fn (array $r): bool => str_contains(mb_strtolower($r['name'].' '.$r['handle']), $term));
        }

        $sort = in_array($o['sort'] ?? null, self::SORTS, true) ? (string) $o['sort'] : 'orders';
        $dir = strtolower((string) ($o['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = in_array((int) ($o['per_page'] ?? 10), self::PAGE_SIZES, true) ? (int) ($o['per_page'] ?? 10) : 10;
        $page = max(1, (int) ($o['page'] ?? 1));
        $present = $list->filter(static fn (array $r): bool => $r[$sort] !== null)->sortBy($sort, SORT_REGULAR, $dir === 'desc');
        $sorted = $present->concat($list->filter(static fn (array $r): bool => $r[$sort] === null))->values();

        return [
            'state' => $collaborations->isEmpty() ? 'empty' : 'ready',
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'can_see_costs' => $canSeeCosts,
            'rows' => $all ? $sorted->all() : $sorted->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'total' => $sorted->count(),
            'page' => $page,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'summary' => $summary,
            'notes' => [
                'clicks' => ['ar' => 'النقرات يعدّها رابط التتبع منذ إصداره، ولا تعني زيارة ولا محادثة ولا شراء.', 'en' => 'Clicks are counted by the tracking link since it was issued. A click is not a visit, a chat or a purchase.'],
                'orders' => ['ar' => 'الطلبات من سجل المتجر مربوطة بكوبون المؤثر فقط. لا يُنسب طلب إلى رابط لعدم وجود دليل يربطهما.', 'en' => 'Orders come from the store ledger, tied by the creator’s coupon only. No order is credited to a link, because nothing ties the two.'],
                'visits' => ['ar' => 'الزيارات والإضافة للسلة لكل مؤثر غير متاحة: لا مصدر يقيسها بدليل.', 'en' => 'Visits and add-to-cart per creator are not available: no source measures them with evidence.'],
                'overlap' => ['ar' => 'التداخل: طلب يحمل الكوبون ونسبه المتجر إلى منصة إعلانية. لا يُحسب للمؤثر، ولا يثبت أي الطرفين صنع البيع.', 'en' => 'Overlap: an order carrying the coupon that the store placed on an ad platform. Not credited to the creator, and it does not prove which of them made the sale.'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function derive(array $r, bool $canSeeCosts): array
    {
        $revenue = $r['revenue_by_currency'];
        $costs = $r['cost_by_currency'];
        $oneRevenue = count($revenue) === 1 ? (string) array_key_first($revenue) : null;
        $oneCost = count($costs) === 1 ? (string) array_key_first($costs) : null;
        $comparable = $oneRevenue !== null && $oneRevenue === $oneCost && $costs[$oneCost] > 0;
        $net = $oneRevenue === null ? null : round((float) $revenue[$oneRevenue], 2);
        $cost = $oneCost === null ? null : round((float) $costs[$oneCost], 2);

        $r['currency'] = $oneRevenue;
        // Several currencies are not added: the row states each and leaves the single figure empty.
        $r['net_revenue'] = $r['has_store'] ? ($r['orders'] === 0 ? 0.0 : $net) : null;
        $r['revenue_by_currency'] = array_map(static fn (float $v): float => round($v, 2), $revenue);
        $r['cost'] = $cost;
        $r['cost_currency'] = $oneCost;
        $r['cost_by_currency'] = $canSeeCosts ? array_map(static fn (float $v): float => round($v, 2), $costs) : [];
        $r['orders'] = $r['has_store'] ? $r['orders'] : null;
        $r['confirmed'] = $r['has_store'] ? $r['confirmed'] : null;
        $r['overlap_orders'] = $r['has_store'] ? $r['overlap_orders'] : null;
        $r['cpa'] = $cost !== null && $cost > 0 && ($r['orders'] ?? 0) > 0 && ($oneRevenue === null || $oneRevenue === $oneCost) ? round($cost / $r['orders'], 2) : null;
        $r['roas'] = $comparable && $net !== null ? round($net / (float) $cost, 2) : null;
        $r['roi'] = $comparable && $net !== null ? round(($net - (float) $cost) / (float) $cost, 4) : null;
        $r['redemption_sources'] = array_keys($r['redemption_sources']);
        unset($r['has_store']);

        return $r;
    }

    /**
     * Store-confirmed orders in the same ledger, by the evidence that ties them. One ledger, one order per row, so this
     * is a split of the store's own count and not a sum of different systems.
     *
     * @param  Collection<int, array<string, mixed>>  $list
     * @param  Collection<string, Collection<int, CommerceOrder>>  $ledger
     * @return array<string, mixed>
     */
    private function summary(Collection $list, Collection $ledger): array
    {
        $live = $ledger->flatten(1)->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null);
        $influencer = $list->filter(static fn (array $r): bool => ! $r['is_ugc'])->sum('orders');
        $ugc = $list->filter(static fn (array $r): bool => $r['is_ugc'])->sum('orders');
        $platforms = $live->filter(static fn (CommerceOrder $o): bool => $o->external_campaign_id !== null || $o->click_id_provider !== null)->count();

        return [
            'influencers' => $list->count(),
            'clicks' => (int) $list->sum('clicks'),
            'orders' => (int) $list->sum('orders'),
            'overlap_orders' => (int) $list->sum('overlap_orders'),
            'has_ledger' => $ledger->isNotEmpty(),
            'comparison' => $ledger->isEmpty() ? [] : [
                ['group' => 'influencers', 'label_ar' => 'مؤثرون', 'label_en' => 'Creators', 'orders' => (int) $influencer],
                ['group' => 'ugc', 'label_ar' => 'محتوى UGC', 'label_en' => 'UGC', 'orders' => (int) $ugc],
                ['group' => 'platforms', 'label_ar' => 'منصات إعلانية', 'label_en' => 'Ad platforms', 'orders' => $platforms],
                ['group' => 'other', 'label_ar' => 'بقية الطلبات أو غير منسوبة', 'label_en' => 'Other or unattributed orders', 'orders' => max(0, $live->count() - (int) $influencer - (int) $ugc - $platforms)],
            ],
        ];
    }

    /** @return Collection<int, CommerceOrder> */
    private function ordersOf(string $tenantId, string $projectId, Carbon $from, Carbon $to): Collection
    {
        $window = $this->timezones->window($projectId, $from, $to);

        return $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders'];
    }
}
