<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-INTEGRATION-001 — what we ask a property for, and what we call it afterwards.
 *
 * Two vocabularies, and they are kept apart on purpose. Google's Data API names are Google's
 * (`screenPageViews`, `keyEvents`); the product's keys are the product's (`page_views`,
 * `key_events`). A request written in the product's words would fail, and a figure stored in
 * Google's words would be the only place in the schema spelled that way.
 *
 * ## These are NOT the advertising metric keys, and must never be read as them
 *
 * `revenue` here is revenue GA4 measured on the client's SITE, under GA4's attribution. `revenue` in
 * `daily_metrics` is revenue an ad platform attributed to its own ads. The keys look alike because
 * they name the same worldly thing; they are not addable, and the separate table is what keeps a
 * query from doing it by accident.
 */
final class Ga4Metrics
{
    /**
     * The Data API metric → the key it is stored under.
     *
     * Deliberately short. Every metric in a `runReport` costs quota — the Data API meters in TOKENS
     * per property, and a wide report costs more than a narrow one — so this is the set a client
     * report actually reads, not everything GA4 publishes.
     *
     * `keyEvents` rather than `conversions`: Google renamed the metric in 2024 and `conversions` is
     * the deprecated alias. Asking for the old name still works today and will stop working without
     * notice, which is the sort of silence that shows up as a zero.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'sessions' => 'sessions',
        'totalUsers' => 'users',
        'newUsers' => 'new_users',
        'screenPageViews' => 'page_views',
        'keyEvents' => 'key_events',
        'transactions' => 'transactions',
        'purchaseRevenue' => 'revenue',
        'engagementRate' => 'engagement_rate',
    ];

    /**
     * The keys that are MONEY, and therefore carry the property's currency.
     *
     * A rate stored with a currency, or revenue stored without one, both end up displayed wrongly —
     * and the second is worse, because a bare number invites the reader to add it to something.
     *
     * @var list<string>
     */
    public const MONEY = ['revenue'];

    /**
     * The keys that are a RATE, not a count — they may never be summed across days.
     *
     * `engagementRate` arrives as a fraction of sessions. Adding a week of fractions produces a
     * number above 1 that reads as a percentage, which is how a 61% engagement rate becomes 427%.
     *
     * @var list<string>
     */
    public const RATES = ['engagement_rate'];

    /** @return list<string> the Data API names, for the request body */
    public static function requested(): array
    {
        return array_keys(self::MAP);
    }

    /** The product's key for a Data API name, or null when Google sent something we did not ask for. */
    public static function keyFor(string $apiName): ?string
    {
        return self::MAP[$apiName] ?? null;
    }

    public static function isMoney(string $key): bool
    {
        return in_array($key, self::MONEY, true);
    }

    public static function isRate(string $key): bool
    {
        return in_array($key, self::RATES, true);
    }
}
