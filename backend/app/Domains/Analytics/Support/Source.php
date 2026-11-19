<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Support;

/**
 * ANALYTICS-DATA-001 — where a number came from, in one vocabulary.
 *
 * Every figure the analytics read APIs return is either a `{value, source}` pair or sits inside a row that carries
 * `source`. The sources are never added to each other: a platform's claim, the site's measurement and the store's
 * ledger are three counts of one sale, and a sum of them is a number nobody measured.
 */
final class Source
{
    /** What an ad platform says it delivered. */
    public const PLATFORM_REPORTED = 'platform_reported';

    /** What the project's GA4 property measured on the site. */
    public const GA4 = 'ga4';

    /** The merchant's own order ledger (Salla / Zid), one row per sale. */
    public const STORE_CONFIRMED = 'store_confirmed';

    /** Clicks counted by the platform's own short-link redirect. */
    public const SHORT_LINK = 'short_link_counter';

    /** An order tied to an influencer by the link's fixed UTM campaign or its coupon, read off the order itself. */
    public const ORDER_EVIDENCE = 'order_evidence';

    /** A recorded click linked to an order by an exact token or click id. */
    public const VERIFIED_TOUCHPOINTS = 'verified_touchpoints';

    /** Spend as the ad platform reported it, in the project currency. */
    public const PLATFORM_SPEND = 'platform_spend';

    /** A figure entered on the collaboration (the creator's fee). */
    public const COLLABORATION = 'collaboration_fee';

    /**
     * @return array{value: mixed, source: string}
     */
    public static function of(mixed $value, string $source, array $extra = []): array
    {
        return ['value' => $value, 'source' => $source] + $extra;
    }
}
