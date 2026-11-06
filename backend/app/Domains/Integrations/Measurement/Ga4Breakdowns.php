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
        // The path a paid visit takes: which campaign brought the session to which landing page, and
        // what those sessions went on to do (engagement, key events, cart, checkout, purchase, revenue).
        'campaign_landing' => ['sessionCampaignName', 'landingPage'],
        // TRAFFIC-DRILLDOWN-001 — all documented Data API dimensions (api-schema).
        'first_user_source_medium' => ['firstUserSource', 'firstUserMedium'],
        'default_channel_group' => ['sessionDefaultChannelGroup'],
        'channel_source' => ['sessionDefaultChannelGroup', 'sessionSourceMedium'],
        'utm_content' => ['sessionManualAdContent', 'sessionCampaignName'],
        'utm_term' => ['sessionManualTerm', 'sessionCampaignName'],
        // GA4-STORE-ANALYTICS-001 — the products the store sold, by GA4's item-scoped `itemName`.
        'product' => ['itemName'],
        'product_category' => ['itemCategory'],
        // GA4-ATTRIBUTION-MATCH-001 — the keys the match reads. Ids: GA4's own campaign id beside the name
        // (utm_id / Google Ads); the Google-Ads-linked campaign id; and the landing query, folded to the
        // click-id FAMILY and UTM values it carried (see Ga4LandingQuery) so a click id is never stored.
        'campaign_id' => ['sessionCampaignId', 'sessionCampaignName'],
        'google_ads_campaign' => ['sessionGoogleAdsCampaignId', 'sessionGoogleAdsCampaignName'],
        'landing_query' => ['landingPagePlusQueryString'],
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

    /**
     * GA4 metric → stored key, for the product breakdown.
     *
     * Item-scoped metrics only: the Data API refuses an item dimension asked with a session metric,
     * so a product row carries units and item revenue and never a session count.
     */
    public const ITEM_METRICS = [
        'itemsViewed' => 'items_viewed',
        'itemsAddedToCart' => 'items_added_to_cart',
        'itemsPurchased' => 'items_purchased',
        'itemRevenue' => 'item_revenue',
    ];

    /** @return array<string, string> */
    public static function metricsFor(string $breakdown): array
    {
        return match ($breakdown) {
            'event' => self::EVENT_METRICS,
            'product', 'product_category' => self::ITEM_METRICS,
            default => self::SESSION_METRICS,
        };
    }

    /** Stored keys that are money, and so carry the property's currency on their row. */
    public const MONEY = ['revenue', 'item_revenue'];

    /** Breakdowns whose rows are folded before they are stored (see Ga4Reporting::breakdownRows). */
    public const FOLDED = ['landing_query'];

    /** Session breakdowns whose counts may be summed across their rows to a total. */
    public const ADDITIVE = ['sessions', 'engaged_sessions', 'key_events', 'add_to_carts', 'checkouts', 'purchases', 'revenue'];
}
