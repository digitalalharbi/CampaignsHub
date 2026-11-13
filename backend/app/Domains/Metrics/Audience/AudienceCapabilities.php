<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Audience;

/**
 * AUDIENCE-001 — what each platform's API documents, and what THIS product actually reads, per dimension.
 *
 * Read from the code on 2026-11-13, not assumed.
 *
 * Each dimension carries two independent facts: `targeting` — whether the ad set's TARGETING setting for
 * it is stored — and `delivery`, the state of the PAID DELIVERY breakdown:
 *
 *   stored                  fetched and stored (audience_breakdown_rows)
 *   api_not_fetched         the provider documents it; this product does not ask yet
 *   unavailable_provider    the provider does not document such a breakdown
 *   unknown                 no public documentation this product could verify
 *
 * `targeting_stored` lists the keys `external_ad_sets.targeting` keeps for the provider and
 * `targeting_lost` what the provider has that the readable summary drops, so «absent from the stored
 * targeting» is never read as «not targeted».
 */
final class AudienceCapabilities
{
    public const DIMENSIONS = ['region', 'city', 'gender', 'age', 'device', 'placement', 'interests', 'professional'];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'meta' => [
                'dimensions' => ['region' => ['targeting' => false, 'delivery' => 'stored'], 'city' => ['targeting' => true, 'delivery' => 'unavailable_provider'], 'gender' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'age' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'device' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'placement' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'interests' => ['targeting' => false, 'delivery' => 'unavailable_provider'], 'professional' => ['targeting' => false, 'delivery' => 'unavailable_provider']],
                'targeting_stored' => ['countries', 'cities', 'age_min', 'age_max', 'genders', 'platforms'],
                'targeting_lost' => ['regions', 'excluded_locations', 'radius', 'location_types', 'interests'],
                'source' => 'GET /act_{id}/insights level=adset breakdowns=region',
                'docs' => 'https://developers.facebook.com/docs/marketing-api/insights/breakdowns',
            ],
            'snapchat' => [
                'dimensions' => ['region' => ['targeting' => true, 'delivery' => 'stored'], 'city' => ['targeting' => false, 'delivery' => 'unknown'], 'gender' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'age' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'device' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'placement' => ['targeting' => true, 'delivery' => 'unknown'], 'interests' => ['targeting' => false, 'delivery' => 'unknown'], 'professional' => ['targeting' => false, 'delivery' => 'unavailable_provider']],
                'targeting_stored' => ['countries', 'regions', 'postal_codes (count)', 'radius_m', 'excluded_countries', 'excluded_regions', 'age_min', 'age_max', 'gender', 'languages', 'placements'],
                'targeting_lost' => ['metros', 'circle centres', 'interests'],
                'source' => 'GET /v1/adsquads/{id}/stats granularity=TOTAL report_dimension=region (estimated; days with at least 30 impressions)',
                'docs' => 'https://developers.snap.com/api/marketing-api/Ads-API/measurement',
            ],
            'tiktok' => [
                'dimensions' => ['region' => ['targeting' => true, 'delivery' => 'stored'], 'city' => ['targeting' => false, 'delivery' => 'unknown'], 'gender' => ['targeting' => true, 'delivery' => 'stored'], 'age' => ['targeting' => true, 'delivery' => 'stored'], 'device' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'placement' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'interests' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'professional' => ['targeting' => false, 'delivery' => 'unavailable_provider']],
                'targeting_stored' => ['locations', 'age_groups', 'gender', 'languages', 'placements'],
                'targeting_lost' => ['excluded_locations', 'location names (ids only)', 'interests'],
                'source' => 'report/integrated/get report_type=AUDIENCE data_level=AUCTION_ADGROUP (age, gender, province_id; province stored as its id)',
                'docs' => 'https://business-api.tiktok.com/portal/docs',
            ],
            'google' => [
                'dimensions' => ['region' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'city' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'gender' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'age' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'device' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'placement' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'interests' => ['targeting' => false, 'delivery' => 'unknown'], 'professional' => ['targeting' => false, 'delivery' => 'unavailable_provider']],
                'targeting_stored' => [],
                'targeting_lost' => ['campaign_criterion locations (incl. negative)', 'proximity radius', 'geo_target_type_setting', 'age_range', 'gender'],
                'source' => 'geographic_view / user_location_view / age_range_view / gender_view (not requested)',
                'docs' => 'https://developers.google.com/google-ads/api/docs/reporting/overview',
            ],
            'x' => [
                'dimensions' => ['region' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'city' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'gender' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'age' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'device' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'placement' => ['targeting' => true, 'delivery' => 'api_not_fetched'], 'interests' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'professional' => ['targeting' => false, 'delivery' => 'unavailable_provider']],
                'targeting_stored' => ['product_type', 'placements'],
                'targeting_lost' => ['targeting_criteria (locations, age, gender, interests)'],
                'source' => 'stats segmentation_type (not requested)',
                'docs' => 'https://developer.x.com/en/docs/x-ads-api/analytics/overview',
            ],
            'linkedin' => [
                'dimensions' => ['region' => ['targeting' => false, 'delivery' => 'api_not_fetched'], 'city' => ['targeting' => false, 'delivery' => 'unavailable_provider'], 'gender' => ['targeting' => false, 'delivery' => 'unavailable_provider'], 'age' => ['targeting' => false, 'delivery' => 'unavailable_provider'], 'device' => ['targeting' => false, 'delivery' => 'unknown'], 'placement' => ['targeting' => false, 'delivery' => 'unknown'], 'interests' => ['targeting' => false, 'delivery' => 'unavailable_provider'], 'professional' => ['targeting' => false, 'delivery' => 'api_not_fetched']],
                'targeting_stored' => [],
                'targeting_lost' => ['targetingCriteria (not read; no ad-set level synced)'],
                'source' => 'adAnalytics pivot=MEMBER_REGION_V2 / MEMBER_JOB_FUNCTION / MEMBER_SENIORITY / MEMBER_INDUSTRY / MEMBER_COMPANY_SIZE (not requested; approximate and thresholded by LinkedIn)',
                'docs' => 'https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/ads-reporting',
            ],
            'openai_ads' => [
                'dimensions' => array_fill_keys(self::DIMENSIONS, ['targeting' => false, 'delivery' => 'unknown']),
                'targeting_stored' => [],
                'targeting_lost' => [],
                'source' => null,
                'docs' => null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function for(string $provider): ?array
    {
        return self::all()[$provider] ?? null;
    }
}
