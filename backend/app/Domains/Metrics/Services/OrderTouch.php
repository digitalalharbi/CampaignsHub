<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Support\AdPlatforms;

/**
 * ATTRIBUTION-MODELS-001 — the ONE reading of where a store order came from.
 *
 * The reconciliation places each ledger order on a platform by its strongest evidence, and the
 * model comparison reads the same order as a touch on a customer's path. Two readers with two
 * rules would let «last touch» and «reconciled» disagree for no reason but the code, so both ask
 * this class.
 *
 * The channel is the platform the evidence names, `influencer` for an order placed on a creator's
 * coupon, or `unattributed` when nothing names anyone. Nothing here guesses: an order without
 * evidence stays unattributed.
 */
final class OrderTouch
{
    public const INFLUENCER = 'influencer';

    public const UNATTRIBUTED = 'unattributed';

    /**
     * @return array{channel:string, platform:?string, method:string, via_coupon:bool, code:?string}
     */
    public static function of(CommerceOrder $order, ?ExternalCampaign $campaign): array
    {
        $method = (string) ($order->attribution_method ?: 'none');
        $raw = $campaign !== null ? $campaign->provider : $order->click_id_provider;
        if ($raw === null && $order->utm_source !== null && $method === 'utm_source_platform_only') {
            $raw = $order->utm_source;
        }
        $platform = $raw === null ? null : AdPlatforms::canonical((string) $raw);
        $viaCoupon = $method === 'influencer_coupon';

        return [
            'channel' => $viaCoupon ? self::INFLUENCER : ($platform ?? self::UNATTRIBUTED),
            'platform' => $platform,
            'method' => $method,
            'via_coupon' => $viaCoupon,
            'code' => $viaCoupon ? (string) $order->coupon_code : null,
        ];
    }
}
