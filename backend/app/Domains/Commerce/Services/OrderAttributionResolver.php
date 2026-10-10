<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * COMMERCE-002 — placing an order under the campaign that produced it, and refusing to guess.
 *
 * ## What each signal can and cannot prove
 *
 * A **click id** (`gclid`, `fbclid`, `ttclid`, …) is minted by the platform at the moment of the
 * click. It proves the visit came from THAT PLATFORM, and it proves nothing about which campaign —
 * resolving one to a campaign requires the platform's own click-lookup API, which none of them
 * offers for this purpose. So a click id gives platform-level attribution and stops there.
 *
 * A **utm_campaign** is typed by whoever built the link. It names a campaign, which is exactly what a
 * click id cannot do, and it is also editable, copyable and frequently stale. So it is matched against
 * campaigns we have actually discovered — by the platform's own campaign id first, then by an exact
 * name — and a value matching nothing attributes nothing.
 *
 * ## The conflict case is the one worth writing down
 *
 * An order carrying `fbclid` and `utm_campaign=<a Google campaign>` is not a puzzle to be resolved by
 * preference: it is a mislabelled link, and it happens constantly when an agency copies a UTM template
 * between platforms. Attributing it to either would put one platform's revenue on another's report.
 * It is recorded as `conflict`, attributed to the platform the click id names and to no campaign, and
 * it stays visible as a number a media buyer can go and fix.
 *
 * ## Never invented
 *
 * An order with no usable signal gets `none` — not «direct», not «organic», not the project's only
 * campaign. A funnel that quietly assigns unattributed revenue to the campaign somebody happens to be
 * running is the single most flattering lie this product could tell.
 */
final class OrderAttributionResolver
{
    /**
     * Common `utm_source` spellings, mapped to the platform they mean.
     *
     * Only unambiguous ones. `newsletter`, `email` and `blog` are deliberately absent — they are real
     * sources and none of them is an ad platform, so mapping them would manufacture paid traffic.
     *
     * These are spellings a MERCHANT writes into their own links, which is why they can be listed:
     * the map says what a human meant by a word they chose, not what a platform appends.
     *
     * @var array<string,string>
     */
    private const SOURCE_PLATFORMS = [
        'facebook' => 'meta', 'fb' => 'meta', 'instagram' => 'meta', 'ig' => 'meta', 'meta' => 'meta',
        'google' => 'google', 'googleads' => 'google', 'google_ads' => 'google', 'adwords' => 'google',
        'tiktok' => 'tiktok', 'tt' => 'tiktok',
        'snapchat' => 'snapchat', 'snap' => 'snapchat',
        'twitter' => 'x', 'x' => 'x',
        'linkedin' => 'linkedin',
        'openai' => 'openai_ads', 'chatgpt' => 'openai_ads', 'openai_ads' => 'openai_ads', 'chatgpt_ads' => 'openai_ads',
    ];

    /**
     * Resolve one order against the campaigns its project already knows about.
     *
     * @param  Collection<int,ExternalCampaign>  $campaigns  the project's discovered platform campaigns
     * @return array{external_campaign_id:?string,unified_campaign_id:?string,attribution_method:string}
     */
    public function resolve(CommerceOrder $order, Collection $campaigns): array
    {
        $clickPlatform = $order->click_id_provider === null
            ? null
            : AdPlatforms::canonical($order->click_id_provider);

        $match = $this->campaignFor($order->utm_campaign, $campaigns);

        // A click id from one platform and a campaign from another: a mislabelled link, recorded as
        // such rather than resolved by preference.
        if ($match !== null && $clickPlatform !== null && AdPlatforms::canonical($match['campaign']->provider) !== $clickPlatform) {
            return $this->answer(null, 'conflict');
        }

        if ($match !== null) {
            return $this->answer($match['campaign'], $match['method']);
        }

        if ($clickPlatform !== null) {
            // The platform is proven; the campaign is not. Attributing platform-level only.
            return $this->answer(null, 'click_id_platform_only');
        }

        /*
         * ATTR-EVIDENCE-INFLUENCER-COUPON-001 — a creator's discount code places the order on the
         * collaboration's campaign. Weaker than a platform's click id (the platform proved the click),
         * stronger than a bare utm_source (a word anybody can type). The asset must be active and a
         * discount code; a link's code never matches an order, and an unknown code is nothing.
         */
        $coupon = $this->couponAsset($order);
        if ($coupon !== null) {
            return [
                'external_campaign_id' => null,
                'unified_campaign_id' => $coupon->collaboration?->campaign_id,
                'attribution_method' => 'influencer_coupon',
            ];
        }

        if ($order->utm_source !== null && isset(self::SOURCE_PLATFORMS[strtolower($order->utm_source)])) {
            return $this->answer(null, 'utm_source_platform_only');
        }

        return $this->answer(null, 'none');
    }

    /**
     * Apply a resolution to the order and save it.
     *
     * `attributed_at` is stamped even when nothing was found, because «we looked and found nothing» and
     * «we have not looked yet» are different states, and only the second is worth re-running.
     */
    public function apply(CommerceOrder $order, Collection $campaigns): CommerceOrder
    {
        $resolution = $this->resolve($order, $campaigns);

        $order->forceFill([...$resolution, 'attributed_at' => Carbon::now()])->save();

        if ($resolution['attribution_method'] === 'influencer_coupon' && ($asset = $this->couponAsset($order)) !== null) {
            // The code's redemptions are the store's confirmed orders carrying it — recounted, never incremented.
            $this->recountRedemptions($asset);
        }

        return $order;
    }

    /** The active discount code this order was placed with, if a creator holds one by that code. */
    private function couponAsset(CommerceOrder $order): ?InfluencerTrackingAsset
    {
        $code = trim((string) $order->coupon_code);
        if ($code === '') {
            return null;
        }

        return InfluencerTrackingAsset::query()
            ->where('kind', 'discount_code')
            ->where('is_active', true)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->with('collaboration')
            ->first();
    }

    /**
     * ATTR-EVIDENCE-INFLUENCER-COUPON-001 — redemptions from the store's own orders: confirmed, not cancelled,
     * carrying the code. Source `platform`, which the asset reserved for exactly this.
     */
    public function recountRedemptions(InfluencerTrackingAsset $asset): InfluencerTrackingAsset
    {
        $count = CommerceOrder::query()
            ->whereRaw('lower(coupon_code) = ?', [mb_strtolower((string) $asset->code)])
            ->whereNull('cancelled_at')
            ->count();

        $asset->forceFill([
            'redemptions' => $count,
            'redemptions_source' => 'platform',
            'redemptions_updated_at' => Carbon::now(),
        ])->save();

        return $asset;
    }

    /**
     * @param  Collection<int,ExternalCampaign>  $campaigns
     * @return array{campaign:ExternalCampaign,method:string}|null
     */
    private function campaignFor(?string $utmCampaign, Collection $campaigns): ?array
    {
        $value = trim((string) $utmCampaign);

        if ($value === '') {
            return null;
        }

        // The platform's own campaign id, which is what a correctly-built dynamic UTM carries.
        $byId = $campaigns->first(fn (ExternalCampaign $c) => (string) $c->external_id === $value);

        if ($byId !== null) {
            return ['campaign' => $byId, 'method' => 'utm_campaign_id'];
        }

        /*
         * An exact, case-insensitive name match — and only when exactly ONE campaign carries it.
         *
         * Two campaigns named «رمضان» across two platforms is the ordinary case in this market, and
         * picking whichever the database returned first would attribute a client's revenue by row
         * order.
         */
        $byName = $campaigns->filter(
            fn (ExternalCampaign $c) => mb_strtolower(trim((string) $c->name)) === mb_strtolower($value),
        );

        if ($byName->count() === 1) {
            return ['campaign' => $byName->first(), 'method' => 'utm_campaign_name'];
        }

        return null;
    }

    /** @return array{external_campaign_id:?string,unified_campaign_id:?string,attribution_method:string} */
    private function answer(?ExternalCampaign $campaign, string $method): array
    {
        return [
            'external_campaign_id' => $campaign?->getKey(),
            'unified_campaign_id' => $campaign?->unified_campaign_id,
            'attribution_method' => $method,
        ];
    }
}
