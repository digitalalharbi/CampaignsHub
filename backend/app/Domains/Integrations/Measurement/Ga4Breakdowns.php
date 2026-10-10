<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

/**
 * GA4-ANALYTICS-PRODUCT-001 — the breakdowns read from a property, in GA4's own dimension names.
 *
 * Session breakdowns all ask the same metrics, so their rows add up across a breakdown for the
 * COUNTS (sessions, engaged sessions, key events, add-to-carts, checkouts, purchases, revenue);
 * users do not add up (one person can arrive from two sources) and are never summed across rows.
 * The event breakdown is per event NAME and measures events, not sessions.
 */
final class Ga4Breakdowns
{
    /** breakdown → GA4 dimensions (one or two) */
    public const DIMENSIONS = [
        'source_medium' => ['sessionSource', 'sessionMedium'],
        'campaign' => ['sessionCampaignName', 'sessionSourceMedium'],
        'landing_page' => ['landingPage'],
        'device' => ['deviceCategory'],
        'country' => ['country'],
        'user_type' => ['newVsReturning'],
        'event' => ['eventName'],
    ];

    /** GA4 metric → stored key, for every session breakdown */
    public const SESSION_METRICS = [
        'sessions' => 'sessions',
        'totalUsers' => 'users',
        'engagedSessions' => 'engaged_sessions',
        'keyEvents' => 'key_events',
        'addToCarts' => 'add_to_carts',
        'checkouts' => 'checkouts',
        'ecommercePurchases' => 'purchases',
        'purchaseRevenue' => 'revenue',
    ];

    /** GA4 metric → stored key, for the event breakdown */
    public const EVENT_METRICS = [
        'eventCount' => 'event_count',
        'keyEvents' => 'key_events',
    ];

    /** @return array<string, string> */
    public static function metricsFor(string $breakdown): array
    {
        return $breakdown === 'event' ? self::EVENT_METRICS : self::SESSION_METRICS;
    }

    /** Session breakdowns whose counts may be summed across their rows to a total. */
    public const ADDITIVE = ['sessions', 'engaged_sessions', 'key_events', 'add_to_carts', 'checkouts', 'purchases', 'revenue'];
}
