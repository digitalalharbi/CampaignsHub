<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Support\AdPlatforms;

/**
 * STORE-ANALYTICS-DEPTH-001 — which source an order ties to, and the evidence that ties it.
 *
 * Read from what the order row itself holds; it writes nothing. {@see OrderAttributionResolver} answers a
 * narrower question, «which AD PLATFORM or campaign», so a `none` from it may still carry evidence of a
 * source that is not an ad platform: a newsletter's UTM, a coupon, a referring site. Those were reading as
 * unattributed. What holds no evidence at all reads `unattributed` with no source, and nothing guesses.
 *
 * The strongest evidence is taken first and the resolver's verdict is never overruled: a `conflict` stays a
 * conflict with no source, however many other signals the row holds.
 */
final class OrderSourceEvidence
{
    public const METHODS = ['utm_campaign', 'click_id', 'utm_source', 'influencer_coupon', 'coupon', 'referral', 'conflict', 'unattributed'];

    /** @var array<string, array{ar: string, en: string}> */
    public const LABELS = [
        'utm_campaign' => ['ar' => 'حملة UTM مطابقة', 'en' => 'Matching UTM campaign'],
        'click_id' => ['ar' => 'معرّف نقرة المنصة', 'en' => 'Platform click id'],
        'utm_source' => ['ar' => 'مصدر UTM', 'en' => 'UTM source'],
        'influencer_coupon' => ['ar' => 'كوبون مؤثر', 'en' => 'Creator coupon'],
        'coupon' => ['ar' => 'كوبون خصم', 'en' => 'Discount coupon'],
        'referral' => ['ar' => 'موقع محيل', 'en' => 'Referring site'],
        'conflict' => ['ar' => 'أدلة متعارضة', 'en' => 'Conflicting evidence'],
        'unattributed' => ['ar' => 'غير منسوب', 'en' => 'Unattributed'],
    ];

    /**
     * @param  array<string, string>  $campaignProviders  external campaign id => its platform
     * @return array{method: string, source: ?string, medium: ?string}
     */
    public function read(CommerceOrder $order, array $campaignProviders = []): array
    {
        $utmSource = $this->clean($order->utm_source);
        $utmMedium = $this->clean($order->utm_medium);

        switch ((string) $order->attribution_method) {
            case 'conflict':
                return ['method' => 'conflict', 'source' => null, 'medium' => null];
            case 'utm_campaign_id':
            case 'utm_campaign_name':
                $provider = $campaignProviders[(string) $order->external_campaign_id] ?? null;

                return ['method' => 'utm_campaign', 'source' => $utmSource ?? ($provider === null ? null : AdPlatforms::canonical($provider)), 'medium' => $utmMedium];
            case 'click_id_platform_only':
                return ['method' => 'click_id', 'source' => $order->click_id_provider === null ? null : AdPlatforms::canonical((string) $order->click_id_provider), 'medium' => $utmMedium];
            case 'influencer_coupon':
                return ['method' => 'influencer_coupon', 'source' => 'coupon', 'medium' => 'influencer'];
            case 'utm_source_platform_only':
                return ['method' => 'utm_source', 'source' => $utmSource, 'medium' => $utmMedium];
        }

        if ($utmSource !== null) {
            return ['method' => 'utm_source', 'source' => $utmSource, 'medium' => $utmMedium];
        }

        if (trim((string) $order->coupon_code) !== '') {
            return ['method' => 'coupon', 'source' => 'coupon', 'medium' => null];
        }

        $referrer = $this->externalReferrer($order);
        if ($referrer !== null) {
            return ['method' => 'referral', 'source' => $referrer, 'medium' => 'referral'];
        }

        return ['method' => 'unattributed', 'source' => null, 'medium' => null];
    }

    /** The landing address with its query and fragment gone: a query string can hold anything, an email among it. */
    public function landingPath(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** The referring site's host, unless the shop is only referring to itself. */
    private function externalReferrer(CommerceOrder $order): ?string
    {
        $host = $this->host($order->referrer_url);
        if ($host === null || $host === $this->host($order->landing_url)) {
            return null;
        }

        return $host;
    }

    private function host(?string $url): ?string
    {
        $host = parse_url(trim((string) $url), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function clean(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' ? null : $value;
    }
}
