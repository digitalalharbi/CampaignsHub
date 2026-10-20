<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Touchpoints;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;

/**
 * TOUCHPOINT-EVIDENCE-001 — decides which recorded touchpoints belong to an order, and says how it knows.
 *
 * Only exact evidence links, and every link is graded:
 *
 *  - `click_id_match` (verified): the order carries the click id and the platform that issued it, and a
 *    touchpoint recorded that same click id for that platform before the order was placed.
 *  - `tracking_token_match` (verified): the order's `utm_content`, or the `ch_tid` parameter on its landing URL,
 *    holds a token WE minted for a touchpoint. Nobody else could have put it there.
 *  - `visitor_key_match` (verified): a first-party event gave a server-confirmed order and a visitor key, and
 *    that visitor has consented touchpoints before the order. Called by that ingestion, never inferred here.
 *
 * Nothing links by similarity. A UTM campaign that merely looks alike is not proof, and a touchpoint that
 * happened AFTER the order cannot have caused it. Linking is idempotent: running it twice makes the same links.
 */
final class TouchpointLinker
{
    public const VERSION = '2026-10-link-1';

    public const TOKEN_PARAM = 'ch_tid';

    /** @return int how many links exist for this order afterwards that this call made */
    public function linkOrder(CommerceOrder $order): int
    {
        if ($order->placed_at === null) {
            return 0;
        }

        $made = 0;
        $base = fn () => MarketingTouchpoint::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('project_id', $order->project_id)
            ->where('occurred_at', '<=', $order->placed_at);

        $clickId = trim((string) $order->click_id);
        $provider = $order->click_id_provider === null ? null : AdPlatforms::canonical((string) $order->click_id_provider);
        if ($clickId !== '' && $provider !== null) {
            foreach ($base()->where('click_id', $clickId)->where('click_id_provider', $provider)->get() as $touch) {
                $made += $this->link($order, $touch, 'click_id_match') ? 1 : 0;
            }
        }

        $tokens = $this->tokensOn($order);
        if ($tokens !== []) {
            foreach ($base()->whereIn('tracking_token', $tokens)->get() as $touch) {
                $made += $this->link($order, $touch, 'tracking_token_match') ? 1 : 0;
            }
        }

        return $made;
    }

    /**
     * A server-confirmed fact from first-party tracking: this visitor placed this order.
     *
     * @return int links made
     */
    public function linkVisitor(CommerceOrder $order, string $visitorKey): int
    {
        if ($order->placed_at === null || $visitorKey === '') {
            return 0;
        }

        $made = 0;
        $touches = MarketingTouchpoint::withoutGlobalScopes()
            ->where('tenant_id', $order->tenant_id)
            ->where('project_id', $order->project_id)
            ->where('visitor_key', $visitorKey)
            ->where('consent_state', MarketingTouchpoint::GRANTED)
            ->where('occurred_at', '<=', $order->placed_at)
            ->get();
        foreach ($touches as $touch) {
            $made += $this->link($order, $touch, 'visitor_key_match') ? 1 : 0;
        }

        return $made;
    }

    /** @return list<string> */
    private function tokensOn(CommerceOrder $order): array
    {
        $found = [];
        $content = trim((string) $order->utm_content);
        if ($content !== '') {
            $found[] = $content;
        }

        $landing = (string) $order->landing_url;
        if ($landing !== '') {
            parse_str((string) parse_url($landing, PHP_URL_QUERY), $query);
            $token = $query[self::TOKEN_PARAM] ?? null;
            if (is_string($token) && $token !== '') {
                $found[] = $token;
            }
        }

        return array_values(array_unique($found));
    }

    private function link(CommerceOrder $order, MarketingTouchpoint $touch, string $method): bool
    {
        $link = OrderTouchpointLink::withoutGlobalScopes()->firstOrCreate(
            ['commerce_order_id' => $order->getKey(), 'touchpoint_id' => $touch->getKey()],
            [
                'tenant_id' => $order->tenant_id,
                'project_id' => $order->project_id,
                'method' => $method,
                'grade' => OrderTouchpointLink::VERIFIED,
                'linker_version' => self::VERSION,
                'linked_at' => Carbon::now(),
            ],
        );

        return $link->wasRecentlyCreated;
    }
}
