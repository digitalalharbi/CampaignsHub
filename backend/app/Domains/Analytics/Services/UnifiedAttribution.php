<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\InfluencerProjectLink;
use App\Domains\Analytics\Support\Reconciliation;
use App\Domains\Analytics\Support\Source;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Metrics\Services\AttributionCredit;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Metrics\Services\OrderTouch;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Metrics\Touchpoints\MarketingTouchpoint;
use App\Domains\Metrics\Touchpoints\OrderJourneys;
use App\Support\AdPlatforms;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ANALYTICS-DATA-001 §7 — the ONE attribution layer, for measurement only.
 *
 * It extends what exists and replaces nothing: the credit arithmetic is {@see AttributionCredit} (the same four rules
 * the report uses), the touches are {@see OrderJourneys} (a recorded click linked to an order by an exact token or click
 * id, with consent), the order's own evidence is {@see OrderTouch}, and the platform claim is the same three-source
 * comparison the site read shows. Nothing here changes a stored number or any platform setting.
 *
 * ## What a conversion is, and who is a channel
 *
 * The conversion is a store-confirmed order (a live one; a fully refunded order is not a sale to share out). Its
 * channels are, in this order of strength:
 *   1. its VERIFIED touchpoints — paid platforms by their click id, an influencer's or an owned link by its token;
 *   2. when there are none, the order's OWN evidence — a UTM or click id naming a platform, an influencer's coupon, or
 *      the influencer link's fixed campaign;
 *   3. when there is nothing at all, `direct_organic`: the store holds no marketing evidence for it. That is not a
 *      claim that the sale was organic — GA4's own direct and organic sessions sit beside, never added.
 *
 * ## The four models
 *
 * last click, first click, linear and position-based (40 / 20 / 40) each share ONE whole order across its touches, so
 * every model's channels add up to exactly the same number of conversions and the same revenue. `totals_by_model`
 * states it, and a test holds it. Every payload carries all four so the board switches without another request.
 *
 * ## Source on every number
 *
 * Credit cells say `verified_touchpoints` or `order_evidence` (or `store_confirmed` for the totals); the platform's
 * claim is `platform_reported`; the site is `ga4`. They are laid side by side and `summed` is false.
 */
final class UnifiedAttribution
{
    public const MODELS = [AttributionCredit::LAST_CLICK, AttributionCredit::FIRST_CLICK, AttributionCredit::LINEAR, AttributionCredit::POSITION_BASED];

    public const DIRECT_ORGANIC = 'direct_organic';

    public const OWNED_LINK = 'owned_link';

    private const PATH_LENGTH = 5;

    private const PATH_ROWS = 10;

    private const LINKS_CAP = 60;

    private const PAIRS_CAP = 10;

    public function __construct(
        private readonly ProjectOrders $orders,
        private readonly ProjectStores $stores,
        private readonly ReportingTimezone $timezones,
        private readonly AttributionTransparency $transparency,
        private readonly SiteReadModel $site,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $config = AttributionCredit::config(['models' => self::MODELS]);
        $three = $this->transparency->threeSources($tenantId, $projectId, $from, $to);
        $hasStore = $this->stores->forProject($tenantId, $projectId)->isNotEmpty();

        $base = [
            'section' => 'attribution',
            'source' => 'multiple',
            'blended_with_other_sources' => false,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'models' => self::MODELS,
            'default_model' => AttributionCredit::LAST_CLICK,
            'config' => ['version' => AttributionCredit::VERSION, 'lookback_days' => $config['lookback_days'], 'weights' => $config['weights'], 'source' => 'attribution_credit'],
            'claim_vs_confirmed' => Reconciliation::from($three),
            'ga4_unpaid' => $this->ga4Unpaid($tenantId, $projectId, $from, $to),
        ];

        if (! $hasStore) {
            return $base + [
                'state' => 'not_linked',
                'status' => 'NO_STORE',
                'channels' => [], 'totals_by_model' => null, 'coverage' => null, 'paths' => null, 'overlap' => $this->emptyOverlap($three, null),
            ];
        }

        /** @var Collection<int, CommerceOrder> $live */
        $live = collect($this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders'])
            ->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null)->values();

        if ($live->isEmpty()) {
            return $base + [
                'state' => 'empty', 'status' => 'LINKED_NO_ORDERS',
                'channels' => [], 'totals_by_model' => null, 'coverage' => null, 'paths' => null, 'overlap' => $this->emptyOverlap($three, 0),
            ];
        }

        $links = InfluencerProjectLink::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->get();
        $byCampaign = $links->keyBy(fn (InfluencerProjectLink $l): string => mb_strtolower($l->utm_campaign));
        $byCoupon = $links->filter(fn (InfluencerProjectLink $l): bool => $l->coupon_code !== null)->keyBy(fn (InfluencerProjectLink $l): string => mb_strtolower((string) $l->coupon_code));
        $influencerShorts = $links->pluck('short_link_id')->map(fn ($id): string => (string) $id)->flip();

        $campaignIds = $live->pluck('external_campaign_id')->filter()->unique()->values()->all();
        $campaigns = $campaignIds === [] ? collect() : ExternalCampaign::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereIn('id', $campaignIds)->get(['id', 'provider'])->keyBy(fn (ExternalCampaign $c): string => (string) $c->getKey());

        $journeys = (new OrderJourneys)->forOrders($tenantId, $projectId, $live, $config['lookback_days']);
        $touchMeta = $this->touchpointLinks($tenantId, $projectId, $journeys);

        $channels = [];
        $totals = array_fill_keys(self::MODELS, ['orders' => 0.0, 'revenue' => 0.0]);
        $coverage = ['orders_total' => $live->count(), 'orders_credited' => 0, 'with_verified_touchpoints' => 0, 'with_order_evidence' => 0, 'direct_organic' => 0, 'excluded_fully_refunded' => 0, 'revenue_withheld_orders' => 0];
        $pathCounts = [];
        $edges = [];
        $nodes = [];
        $sets = [];
        $multiChannel = 0;
        $currency = null;

        foreach ($live as $order) {
            $net = $order->netRevenue();
            if ($net !== null && $net <= 0.0) {
                $coverage['excluded_fully_refunded']++;

                continue;
            }
            $currency ??= $order->currency;
            $revenue = $net ?? 0.0;
            if ($net === null) {
                $coverage['revenue_withheld_orders']++;
            }
            $coverage['orders_credited']++;

            [$touches, $basis] = $this->touchesOf($order, $journeys[(string) $order->getKey()] ?? [], $touchMeta, $influencerShorts, $byCampaign, $byCoupon, $campaigns);
            $coverage[match ($basis) {
                Source::VERIFIED_TOUCHPOINTS => 'with_verified_touchpoints',
                Source::ORDER_EVIDENCE => 'with_order_evidence',
                default => 'direct_organic',
            }]++;

            $placed = $order->placed_at ?? $to;
            foreach (self::MODELS as $model) {
                $shares = AttributionCredit::split($model, $touches, $placed, $config);
                foreach ($shares as $channel => $share) {
                    $channels[$channel] ??= ['basis' => []];
                    $channels[$channel]['basis'][$basis] = true;
                    $channels[$channel]['models'][$model]['orders'] = ($channels[$channel]['models'][$model]['orders'] ?? 0.0) + $share;
                    $channels[$channel]['models'][$model]['revenue'] = ($channels[$channel]['models'][$model]['revenue'] ?? 0.0) + $share * $revenue;
                }
                $totals[$model]['orders'] += array_sum($shares);
                $totals[$model]['revenue'] += array_sum($shares) * $revenue;
            }

            // The ordered, collapsed path of channels, last five.
            $steps = [];
            foreach ($touches as $t) {
                if ($steps === [] || end($steps) !== $t['channel']) {
                    $steps[] = $t['channel'];
                }
            }
            $steps = array_slice($steps, -self::PATH_LENGTH);
            $key = implode('>', $steps);
            $pathCounts[$key] ??= ['steps' => $steps, 'orders' => 0, 'revenue' => 0.0, 'verified_orders' => 0];
            $pathCounts[$key]['orders']++;
            $pathCounts[$key]['revenue'] += $revenue;
            $pathCounts[$key]['verified_orders'] += $basis === Source::VERIFIED_TOUCHPOINTS ? 1 : 0;
            $this->edges($steps, $revenue, $basis === Source::VERIFIED_TOUCHPOINTS, $edges, $nodes);

            $distinct = array_values(array_unique(array_column($touches, 'channel')));
            if ($basis === Source::VERIFIED_TOUCHPOINTS && count($distinct) > 1) {
                $multiChannel++;
            }
            $sets[] = $distinct;
        }

        $claimed = [];
        foreach ($three['rows'] as $r) {
            $claimed[(string) $r['platform']] = $r;
        }
        foreach (array_keys($claimed) as $platform) {
            $channels[$platform] ??= ['basis' => [], 'models' => []];
        }

        $rows = [];
        foreach ($channels as $channel => $c) {
            $models = [];
            foreach (self::MODELS as $model) {
                $m = $c['models'][$model] ?? ['orders' => 0.0, 'revenue' => 0.0];
                $models[$model] = ['orders' => round($m['orders'], 4), 'revenue' => round($m['revenue'], 2)];
            }
            $claim = $claimed[$channel] ?? null;
            $rows[] = [
                'channel' => $channel,
                'kind' => $this->kind($channel),
                'source' => isset($c['basis'][Source::VERIFIED_TOUCHPOINTS]) ? Source::VERIFIED_TOUCHPOINTS : Source::ORDER_EVIDENCE,
                'evidence' => array_keys($c['basis']),
                'models' => $models,
                'platform_reported' => $claim === null || $claim['platform_reported'] === null ? null : $claim['platform_reported'] + ['source' => Source::PLATFORM_REPORTED],
                'ga4_confirmed' => $claim === null || $claim['ga4_measured'] === null ? null : $claim['ga4_measured'] + ['source' => Source::GA4],
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['models'][AttributionCredit::LAST_CLICK]['orders'], $a['channel']] <=> [$a['models'][AttributionCredit::LAST_CLICK]['orders'], $b['channel']]);

        $pathRows = array_values($pathCounts);
        usort($pathRows, static fn (array $a, array $b): int => [$b['orders'], implode('>', $a['steps'])] <=> [$a['orders'], implode('>', $b['steps'])]);
        $multiRows = array_values(array_filter($pathRows, static fn (array $p): bool => count($p['steps']) > 1));

        usort($edges, static fn (array $a, array $b): int => [$b['orders'], $a['source'].$a['target']] <=> [$a['orders'], $b['source'].$b['target']]);
        $edges = array_slice($edges, 0, self::LINKS_CAP);
        $used = [];
        foreach ($edges as $e) {
            $used[$e['source']] = true;
            $used[$e['target']] = true;
        }

        return $base + [
            'state' => 'ready',
            'status' => 'LINKED',
            'currency' => $currency,
            'coverage' => $coverage + ['share_with_verified_touchpoints' => $coverage['orders_credited'] > 0 ? round($coverage['with_verified_touchpoints'] / $coverage['orders_credited'], 4) : null, 'source' => Source::STORE_CONFIRMED],
            'totals_by_model' => array_map(static fn (array $t): array => ['orders' => round($t['orders'], 4), 'revenue' => round($t['revenue'], 2), 'source' => Source::STORE_CONFIRMED], $totals),
            'channels' => $rows,
            'paths' => [
                'source' => Source::VERIFIED_TOUCHPOINTS,
                'max_length' => self::PATH_LENGTH,
                'distinct' => count($pathCounts),
                'multi_touch_orders' => array_sum(array_column($multiRows, 'orders')),
                'rows' => array_map(static fn (array $p): array => [...$p, 'revenue' => round($p['revenue'], 2)], array_slice($multiRows, 0, self::PATH_ROWS)),
                'sankey' => [
                    'source' => Source::VERIFIED_TOUCHPOINTS,
                    'nodes' => array_values(array_filter($nodes, static fn (array $n): bool => isset($used[$n['id']]))),
                    'links' => array_map(static fn (array $e): array => [...$e, 'revenue' => round($e['revenue'], 2)], $edges),
                ],
            ],
            'overlap' => $this->overlap($sets, $multiChannel, $coverage['with_verified_touchpoints'], $three, $coverage['orders_credited']),
        ];
    }

    /**
     * An order's touches, oldest first, and what they rest on.
     *
     * @param  list<array{channel: string, at: Carbon, is_paid: bool, method: string, touchpoint_id: string}>  $verified
     * @param  array<string, array{smart_link_id: ?string, influencer_asset_id: ?string}>  $meta
     * @param  Collection<string, int>  $influencerShorts  short-link ids of the project's influencer links
     * @param  Collection<string, InfluencerProjectLink>  $byCampaign
     * @param  Collection<string, InfluencerProjectLink>  $byCoupon
     * @param  Collection<string, ExternalCampaign>  $campaigns
     * @return array{0: list<array{channel: string, at: CarbonInterface, is_paid: bool}>, 1: string}
     */
    private function touchesOf(CommerceOrder $order, array $verified, array $meta, Collection $influencerShorts, Collection $byCampaign, Collection $byCoupon, Collection $campaigns): array
    {
        if ($verified !== []) {
            $touches = [];
            foreach ($verified as $t) {
                $m = $meta[$t['touchpoint_id']] ?? ['smart_link_id' => null, 'influencer_asset_id' => null];
                $channel = match (true) {
                    $m['smart_link_id'] !== null && $influencerShorts->has($m['smart_link_id']) => OrderTouch::INFLUENCER,
                    $m['influencer_asset_id'] !== null => OrderTouch::INFLUENCER,
                    $t['channel'] === 'smart_link' => self::OWNED_LINK,
                    default => $t['channel'],
                };
                $touches[] = ['channel' => $channel, 'at' => $t['at'], 'is_paid' => $t['is_paid']];
            }

            return [$touches, Source::VERIFIED_TOUCHPOINTS];
        }

        $at = $order->placed_at ?? Carbon::now();
        $own = OrderTouch::of($order, $order->external_campaign_id === null ? null : $campaigns->get((string) $order->external_campaign_id));
        $channel = $own['channel'];
        if ($channel === OrderTouch::UNATTRIBUTED) {
            $campaign = mb_strtolower(trim((string) $order->utm_campaign));
            $coupon = mb_strtolower(trim((string) $order->coupon_code));
            if (($campaign !== '' && $byCampaign->has($campaign)) || ($coupon !== '' && $byCoupon->has($coupon))) {
                $channel = OrderTouch::INFLUENCER;
            }
        }
        if ($channel === OrderTouch::UNATTRIBUTED) {
            return [[['channel' => self::DIRECT_ORGANIC, 'at' => $at, 'is_paid' => false]], 'none'];
        }

        return [[['channel' => $channel, 'at' => $at, 'is_paid' => in_array($channel, AdPlatforms::ORDER, true)]], Source::ORDER_EVIDENCE];
    }

    /**
     * The smart-link and influencer ids of the touchpoints the journeys named (one query, scoped to the project).
     *
     * @param  array<string, list<array{touchpoint_id: string}>>  $journeys
     * @return array<string, array{smart_link_id: ?string, influencer_asset_id: ?string}>
     */
    private function touchpointLinks(string $tenantId, string $projectId, array $journeys): array
    {
        $ids = [];
        foreach ($journeys as $touches) {
            foreach ($touches as $t) {
                $ids[$t['touchpoint_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (MarketingTouchpoint::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->whereIn('id', array_keys($ids))->get(['id', 'smart_link_id', 'influencer_asset_id']) as $row) {
            $out[(string) $row->getKey()] = [
                'smart_link_id' => $row->smart_link_id === null ? null : (string) $row->smart_link_id,
                'influencer_asset_id' => $row->influencer_asset_id === null ? null : (string) $row->influencer_asset_id,
            ];
        }

        return $out;
    }

    /**
     * @param  list<string>  $steps
     * @param  array<string, array<string, mixed>>  $edges
     * @param  array<string, array<string, mixed>>  $nodes
     */
    private function edges(array $steps, float $revenue, bool $verified, array &$edges, array &$nodes): void
    {
        $ids = [];
        foreach ($steps as $i => $channel) {
            $ids[] = ($i + 1).':'.$channel;
            $nodes[($i + 1).':'.$channel] ??= ['id' => ($i + 1).':'.$channel, 'channel' => $channel, 'step' => $i + 1];
        }
        $ids[] = 'purchase';
        $nodes['purchase'] ??= ['id' => 'purchase', 'channel' => 'purchase', 'step' => self::PATH_LENGTH + 1];

        for ($i = 0, $n = count($ids) - 1; $i < $n; $i++) {
            $k = $ids[$i].'->'.$ids[$i + 1];
            $edges[$k] ??= ['source' => $ids[$i], 'target' => $ids[$i + 1], 'orders' => 0, 'revenue' => 0.0, 'verified_orders' => 0];
            $edges[$k]['orders']++;
            $edges[$k]['revenue'] += $revenue;
            $edges[$k]['verified_orders'] += $verified ? 1 : 0;
        }
    }

    private function kind(string $channel): string
    {
        return match (true) {
            $channel === OrderTouch::INFLUENCER => 'influencer',
            $channel === self::OWNED_LINK => 'owned_link',
            $channel === self::DIRECT_ORGANIC => 'direct_organic',
            default => 'platform',
        };
    }

    /**
     * Overlap between channels, from the orders whose touches were verified.
     *
     * `overlap_ratio` is the share of orders that had a verified touchpoint and were reached by more than one channel;
     * each pair's `ratio` is orders touched by both ÷ orders touched by either (only a verified path can touch two).
     * `claim_overlap` is the other face: the platforms' combined claim against the ledger's own count.
     *
     * @param  list<list<string>>  $sets
     * @param  array<string, mixed>  $three
     * @return array<string, mixed>
     */
    private function overlap(array $sets, int $multi, int $withVerified, array $three, int $credited): array
    {
        $either = [];
        $both = [];
        foreach ($sets as $set) {
            sort($set);
            foreach ($set as $a) {
                $either[$a] = ($either[$a] ?? 0) + 1;
            }
            foreach ($set as $i => $a) {
                foreach (array_slice($set, $i + 1) as $b) {
                    $both[$a.'|'.$b] = ($both[$a.'|'.$b] ?? 0) + 1;
                }
            }
        }
        $pairs = [];
        foreach ($both as $key => $n) {
            [$a, $b] = explode('|', $key);
            $union = $either[$a] + $either[$b] - $n;
            $pairs[] = ['channels' => [$a, $b], 'orders_both' => $n, 'orders_either' => $union, 'ratio' => $union > 0 ? round($n / $union, 4) : null, 'source' => Source::VERIFIED_TOUCHPOINTS];
        }
        usort($pairs, static fn (array $x, array $y): int => [$y['orders_both'], implode($x['channels'])] <=> [$x['orders_both'], implode($y['channels'])]);

        return [
            'source' => Source::VERIFIED_TOUCHPOINTS,
            'orders_with_verified_touchpoints' => $withVerified,
            'multi_channel_orders' => $multi,
            'overlap_ratio' => $withVerified > 0 ? round($multi / $withVerified, 4) : null,
            'pairs' => array_slice($pairs, 0, self::PAIRS_CAP),
            'claim_overlap' => $this->claimOverlap($three, $credited),
        ];
    }

    /**
     * @param  array<string, mixed>  $three
     * @return array<string, mixed>
     */
    private function emptyOverlap(array $three, ?int $storeOrders): array
    {
        return [
            'source' => Source::VERIFIED_TOUCHPOINTS,
            'orders_with_verified_touchpoints' => null,
            'multi_channel_orders' => null,
            'overlap_ratio' => null,
            'pairs' => [],
            'claim_overlap' => $this->claimOverlap($three, $storeOrders),
        ];
    }

    /**
     * @param  array<string, mixed>  $three
     * @return array<string, mixed>
     */
    private function claimOverlap(array $three, ?int $storeOrders): array
    {
        $claimed = $three['sources'][0]['orders'] ?? null;

        return [
            'platform_claimed_orders' => Source::of($claimed, Source::PLATFORM_REPORTED),
            'store_orders' => Source::of($storeOrders, Source::STORE_CONFIRMED),
            // Above 1: the platforms together claim more sales than the ledger holds, so some are claimed twice.
            'claimed_per_store_order' => $claimed !== null && $storeOrders !== null && $storeOrders > 0 ? round($claimed / $storeOrders, 4) : null,
            'source' => 'derived_from_the_two_cells_above',
        ];
    }

    /**
     * GA4's direct and organic sessions: the unpaid side as the SITE saw it, beside the credit and never added to it.
     *
     * @return array<string, mixed>
     */
    private function ga4Unpaid(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $groups = $this->site->channelGroups($tenantId, $projectId, $from, $to);
        if ($groups === []) {
            return ['available' => false, 'source' => Source::GA4, 'rows' => []];
        }
        $paid = static fn (string $name): bool => str_starts_with($name, 'Paid') || in_array($name, ['Display', 'Cross-network'], true);

        return [
            'available' => true,
            'source' => Source::GA4,
            'rows' => array_values(array_filter($groups, static fn (array $g): bool => ! $paid((string) $g['channel']))),
        ];
    }
}
