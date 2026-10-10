<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ATTRIBUTION-MODELS-001 — first touch, last touch, assists and conversion paths, from the evidence
 * the store actually holds (Owner directive 7: «decision-grade attribution where evidence exists»).
 *
 * What a TOUCH is here, stated because everything below depends on it: an ORDER the store recorded,
 * read through {@see OrderTouch} — the platform its UTM, click id or campaign names, a creator's
 * coupon, or nothing. A customer's path is the sequence of those touches across their orders. The
 * visits BETWEEN orders are not seen by a store, so this is never presented as session-level
 * multi-touch, and no credit is split fractionally between touches: every model below gives a whole
 * order to one channel, or counts an assist without moving the order.
 *
 * - Last touch: the order's own evidence. The same placement the reconciliation makes.
 * - First touch: the evidence on the customer's FIRST order with this store, at any date before the
 *   window's end. An order with no customer on it is its own first touch, and is counted as such.
 * - Assisted: a channel on one of the customer's orders in the LOOKBACK_DAYS before this one that is
 *   not the last touch. The order is not moved; the channel is credited with having been there.
 * - Paths: the same lookback, so a customer of three years does not drag a three-year path behind
 *   every order. First touch alone reaches back to the very first order: it is the acquisition.
 * - Platform-claimed and GA4 sit beside these as the other layers, never added to them.
 */
final class AttributionModelComparison
{
    private const PATH_CAP = 10;

    private const PATH_LENGTH = 5;

    /** How far back an earlier order still counts as an assist or a step on the path. */
    public const LOOKBACK_DAYS = 90;

    /**
     * @param  Collection<int,CommerceOrder>  $orders  the window's orders as ProjectOrders loaded them
     * @param  list<array<string,mixed>>  $platforms  the per-platform rows AttributionTransparency built
     * @return array<string,mixed>
     */
    public function build(bool $hasStore, string $tenantId, string $projectId, Collection $orders, Carbon $to, array $platforms): array
    {
        $basis = [
            'basis_ar' => 'كل لمسة هنا طلبٌ سجّله المتجر بدليله: رابط UTM أو معرّف نقر أو حملة أو كوبون مؤثر. الزيارات بين الطلبات لا يراها المتجر، فلا يُقسَّم أي طلب بين القنوات. أول لمسة هي أول طلب للعميل مع المتجر، والمساعدة والمسارات تقرأ طلبات آخر '.self::LOOKBACK_DAYS.' يومًا قبل كل طلب.',
            'basis_en' => 'Each touch here is an order the store recorded with its evidence: a UTM link, a click id, a campaign or a creator coupon. Visits between orders are not seen by a store, so no order is split between channels. First touch is the customer’s first order with the store; assists and paths read the orders in the '.self::LOOKBACK_DAYS.' days before each order.',
            'lookback_days' => self::LOOKBACK_DAYS,
        ];

        if (! $hasStore) {
            return ['available' => false, 'unavailable_reason' => 'no_store_connected', ...$basis, 'channels' => [], 'paths' => null, 'coverage' => null];
        }

        /** @var Collection<int,CommerceOrder> $live */
        $live = $orders->filter(fn (CommerceOrder $o) => $o->cancelled_at === null)->values();

        $customerIds = $live->pluck('commerce_customer_id')->filter()->unique()->values()->all();
        $history = $customerIds === []
            ? collect()
            : CommerceOrder::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->whereIn('commerce_customer_id', $customerIds)
                ->whereNull('cancelled_at')
                ->where('placed_at', '<=', $to)
                ->orderBy('placed_at')
                ->orderBy('id')
                ->get(['id', 'external_id', 'external_account_id', 'commerce_customer_id', 'external_campaign_id', 'attribution_method', 'click_id_provider', 'utm_source', 'coupon_code', 'placed_at']);

        $campaignIds = $live->pluck('external_campaign_id')->merge($history->pluck('external_campaign_id'))->filter()->unique()->values()->all();
        $campaigns = $campaignIds === []
            ? collect()
            : ExternalCampaign::withoutGlobalScopes()->whereIn('id', $campaignIds)->get(['id', 'provider'])->keyBy(fn (ExternalCampaign $c) => (string) $c->getKey());

        $channelOf = static fn (CommerceOrder $o): string => OrderTouch::of(
            $o,
            $o->external_campaign_id === null ? null : $campaigns->get((string) $o->external_campaign_id),
        )['channel'];

        /*
         * Each customer's touches in the order they happened. The same order synced through two
         * connections of one shop is one touch, keyed the way ProjectOrders collapses it.
         */
        $paths = [];
        foreach ($history as $o) {
            $customer = (string) $o->commerce_customer_id;
            $key = $o->external_account_id.'|'.$o->external_id;
            $paths[$customer] ??= [];
            $paths[$customer][$key] = ['id' => (string) $o->getKey(), 'channel' => $channelOf($o), 'at' => $o->placed_at];
        }

        $rows = [];
        $row = static fn (): array => [
            'last_touch' => ['orders' => 0, 'revenue' => 0.0],
            'first_touch' => ['orders' => 0, 'revenue' => 0.0],
            'assisted' => ['orders' => 0, 'revenue' => 0.0],
            'evidence' => ['click_id' => 0, 'utm' => 0, 'coupon' => 0],
        ];
        $pathCounts = [];
        $multiTouch = 0;
        $withCustomer = 0;
        $withheld = 0;
        $currency = null;

        foreach ($live as $order) {
            $touch = OrderTouch::of($order, $order->external_campaign_id === null ? null : $campaigns->get((string) $order->external_campaign_id));
            $last = $touch['channel'];
            $net = $order->netRevenue();
            if ($net === null) {
                $withheld++;
            }
            $revenue = $net ?? 0.0;
            $currency ??= $order->currency;

            $first = $last;
            $before = [];
            if ($order->commerce_customer_id !== null && isset($paths[(string) $order->commerce_customer_id])) {
                $withCustomer++;
                $since = $order->placed_at?->copy()->subDays(self::LOOKBACK_DAYS);
                $seenFirst = false;
                foreach ($paths[(string) $order->commerce_customer_id] as $step) {
                    if ($step['id'] === (string) $order->getKey()) {
                        break;
                    }
                    if (! $seenFirst) {
                        $first = $step['channel'];
                        $seenFirst = true;
                    }
                    if ($since === null || ($step['at'] !== null && $step['at']->gte($since))) {
                        $before[] = $step['channel'];
                    }
                }
            }

            $rows[$last] ??= $row();
            $rows[$last]['last_touch']['orders']++;
            $rows[$last]['last_touch']['revenue'] += $revenue;

            $rows[$first] ??= $row();
            $rows[$first]['first_touch']['orders']++;
            $rows[$first]['first_touch']['revenue'] += $revenue;

            foreach (array_unique($before) as $assist) {
                if ($assist === $last) {
                    continue;
                }
                $rows[$assist] ??= $row();
                $rows[$assist]['assisted']['orders']++;
                $rows[$assist]['assisted']['revenue'] += $revenue;
            }

            if ($touch['method'] === 'click_id_platform_only' || ($order->click_id_provider !== null && $touch['platform'] !== null && AdPlatforms::canonical((string) $order->click_id_provider) === $touch['platform'])) {
                $rows[$last]['evidence']['click_id']++;
            }
            if (in_array($touch['method'], ['utm_campaign_id', 'utm_campaign_name', 'utm_source_platform_only'], true)) {
                $rows[$last]['evidence']['utm']++;
            }
            if ($touch['via_coupon']) {
                $rows[$last]['evidence']['coupon']++;
            }

            // The path that led to THIS order: earlier touches, then this one, repeats in a row read once.
            $steps = [];
            foreach ([...$before, $last] as $channel) {
                if ($steps === [] || end($steps) !== $channel) {
                    $steps[] = $channel;
                }
            }
            if (count($steps) > 1) {
                $multiTouch++;
            }
            $truncated = count($steps) > self::PATH_LENGTH;
            $steps = array_slice($steps, -self::PATH_LENGTH);
            $pathKey = ($truncated ? '…>' : '').implode('>', $steps);
            $pathCounts[$pathKey] ??= ['steps' => $steps, 'truncated' => $truncated, 'orders' => 0, 'revenue' => 0.0];
            $pathCounts[$pathKey]['orders']++;
            $pathCounts[$pathKey]['revenue'] += $revenue;
        }

        $claimed = [];
        foreach ($platforms as $p) {
            $claimed[(string) $p['provider']] = $p;
        }
        foreach (array_keys($claimed) as $provider) {
            $rows[$provider] ??= $row();
        }

        $channels = [];
        foreach ($rows as $channel => $r) {
            $p = $claimed[$channel] ?? null;
            $channels[] = [
                'channel' => $channel,
                'kind' => match ($channel) {
                    OrderTouch::INFLUENCER => 'influencer',
                    OrderTouch::UNATTRIBUTED => 'unattributed',
                    default => 'platform',
                },
                'last_touch' => ['orders' => $r['last_touch']['orders'], 'revenue' => round($r['last_touch']['revenue'], 2)],
                'first_touch' => ['orders' => $r['first_touch']['orders'], 'revenue' => round($r['first_touch']['revenue'], 2)],
                'assisted' => ['orders' => $r['assisted']['orders'], 'revenue' => round($r['assisted']['revenue'], 2)],
                'evidence' => $r['evidence'],
                // The other layers, beside — never added. Null when the layer does not exist for this channel.
                'platform_claimed_orders' => $p === null ? null : $p['platform_reported_orders'],
                'claim_includes_view_through' => $p === null ? null : ($p['attribution']['includes_view_through'] ?? null),
                'claim_click_through_days' => $p === null ? null : ($p['attribution']['click_through_days'] ?? null),
                'claim_view_through_days' => $p === null ? null : ($p['attribution']['view_through_days'] ?? null),
                'ga4_purchases' => $p === null ? null : ($p['ga4_purchases'] ?? null),
            ];
        }

        $order = [OrderTouch::INFLUENCER => 1, OrderTouch::UNATTRIBUTED => 2];
        $platformRows = AdPlatforms::sortRows(array_values(array_filter($channels, static fn (array $c): bool => $c['kind'] === 'platform')), 'channel');
        $others = array_values(array_filter($channels, static fn (array $c): bool => $c['kind'] !== 'platform'));
        usort($others, static fn (array $a, array $b): int => ($order[$a['channel']] ?? 9) <=> ($order[$b['channel']] ?? 9));

        $top = array_values($pathCounts);
        usort($top, static fn (array $a, array $b): int => [$b['orders'], implode('>', $a['steps'])] <=> [$a['orders'], implode('>', $b['steps'])]);

        return [
            'available' => true,
            'unavailable_reason' => null,
            ...$basis,
            'currency' => $currency,
            'channels' => [...$platformRows, ...$others],
            'paths' => [
                'distinct' => count($pathCounts),
                'cap' => self::PATH_CAP,
                'max_length' => self::PATH_LENGTH,
                'multi_touch_orders' => $multiTouch,
                'rows' => array_map(static fn (array $p): array => [...$p, 'revenue' => round($p['revenue'], 2)], array_slice($top, 0, self::PATH_CAP)),
            ],
            'coverage' => [
                'orders' => $live->count(),
                'orders_with_customer' => $withCustomer,
                'share_with_customer' => $live->count() > 0 ? round($withCustomer / $live->count(), 4) : null,
                'revenue_withheld_orders' => $withheld,
            ],
        ];
    }
}
