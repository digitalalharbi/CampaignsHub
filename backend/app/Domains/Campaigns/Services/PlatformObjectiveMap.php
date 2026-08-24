<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;

/**
 * What each platform calls an objective, translated into ours (REPORT-OBJECTIVE-002).
 *
 * ## Why this is a table and not a heuristic
 *
 * The objective decides whether a campaign's spend lands in a client's cost per order, so it has to
 * come from what the platform actually reported. The alternative everyone reaches for — reading the
 * campaign's NAME — is explicitly forbidden by the requirement, and rightly: «Ramadan Sale» is
 * routinely a reach buy, and classifying it by its name puts a brand budget in the numerator of a
 * CPA nobody can then explain.
 *
 * ## Fail-safe by construction
 *
 * An unrecognised value returns **null**, and null means «nobody has classified this yet» rather
 * than any objective. The caller leaves `objective_source` at `unset` and keeps the platform's raw
 * string so an operator can see exactly what was said and correct it.
 *
 * The consequence of that choice is deliberate and asymmetric. `CampaignObjective::Other` sits on
 * the awareness path, so an unclassified campaign's spend never reaches the sales CPA: the cost of
 * being wrong is a cost-per-order that is too LOW, which understates the product's own value, rather
 * than one inflated by brand spend, which misleads a budget decision. Only the second does damage.
 *
 * ## Both generations of Meta's names
 *
 * Meta renamed its objectives in 2022 (`OUTCOME_*`) and ad accounts still carry campaigns created
 * under the old names. Dropping the legacy set would leave every campaign older than the rename
 * unclassified — a silent gap that grows the further back a report's window reaches.
 */
final class PlatformObjectiveMap
{
    /**
     * @var array<string, array<string, CampaignObjective>>
     */
    private const MAP = [
        'meta' => [
            // Current (Outcome-Driven Ad Experiences, 2022 onwards).
            'OUTCOME_AWARENESS' => CampaignObjective::Awareness,
            'OUTCOME_TRAFFIC' => CampaignObjective::Traffic,
            'OUTCOME_ENGAGEMENT' => CampaignObjective::Engagement,
            'OUTCOME_LEADS' => CampaignObjective::Leads,
            'OUTCOME_APP_PROMOTION' => CampaignObjective::AppInstalls,
            'OUTCOME_SALES' => CampaignObjective::Sales,
            // Legacy — still on every campaign created before the rename.
            'BRAND_AWARENESS' => CampaignObjective::Awareness,
            'REACH' => CampaignObjective::Reach,
            'VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'LINK_CLICKS' => CampaignObjective::Traffic,
            'LANDING_PAGE_VIEWS' => CampaignObjective::LandingPageViews,
            'POST_ENGAGEMENT' => CampaignObjective::Engagement,
            'PAGE_LIKES' => CampaignObjective::Engagement,
            'EVENT_RESPONSES' => CampaignObjective::Engagement,
            'MESSAGES' => CampaignObjective::Engagement,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'APP_INSTALLS' => CampaignObjective::AppInstalls,
            'CONVERSIONS' => CampaignObjective::Conversions,
            'PRODUCT_CATALOG_SALES' => CampaignObjective::Sales,
            'CATALOG_SALES' => CampaignObjective::Sales,
            'STORE_VISITS' => CampaignObjective::StoreVisits,
            'STORE_TRAFFIC' => CampaignObjective::StoreVisits,
        ],

        /*
         * Google reports `advertising_channel_type` on the campaign and the marketing OBJECTIVE
         * separately, and only the second answers the question this map is asked. A channel type is
         * where an ad runs, not what it is for: SEARCH serves lead campaigns and shopping campaigns
         * alike, so classifying by it would put both in the same bucket. Only real objective values
         * are listed; a channel type arriving here is unrecognised, which is the correct answer.
         */
        'google' => [
            'SALES' => CampaignObjective::Sales,
            'LEADS' => CampaignObjective::Leads,
            'WEBSITE_TRAFFIC' => CampaignObjective::Traffic,
            'PRODUCT_AND_BRAND_CONSIDERATION' => CampaignObjective::Engagement,
            'BRAND_AWARENESS_AND_REACH' => CampaignObjective::Awareness,
            'APP_PROMOTION' => CampaignObjective::AppInstalls,
            'LOCAL_STORE_VISITS_AND_PROMOTIONS' => CampaignObjective::StoreVisits,
            'DEMAND_GEN' => CampaignObjective::Engagement,
        ],

        'tiktok' => [
            'REACH' => CampaignObjective::Reach,
            'RF_REACH' => CampaignObjective::Reach,
            'VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'RF_VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'TRAFFIC' => CampaignObjective::Traffic,
            'ENGAGEMENT' => CampaignObjective::Engagement,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'APP_PROMOTION' => CampaignObjective::AppInstalls,
            'APP_INSTALL' => CampaignObjective::AppInstalls,
            'WEB_CONVERSIONS' => CampaignObjective::Conversions,
            'CONVERSIONS' => CampaignObjective::Conversions,
            'PRODUCT_SALES' => CampaignObjective::Sales,
            'CATALOG_SALES' => CampaignObjective::Sales,
        ],

        'snapchat' => [
            'AWARENESS' => CampaignObjective::Awareness,
            'BRAND_AWARENESS' => CampaignObjective::Awareness,
            'VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'ENGAGEMENT' => CampaignObjective::Engagement,
            'DRIVE_TRAFFIC_TO_WEBSITE' => CampaignObjective::Traffic,
            'DRIVE_TRAFFIC_TO_APP' => CampaignObjective::Traffic,
            'PROMOTE_PLACES' => CampaignObjective::StoreVisits,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'APP_INSTALLS' => CampaignObjective::AppInstalls,
            'APP_CONVERSIONS' => CampaignObjective::Conversions,
            'WEBSITE_CONVERSIONS' => CampaignObjective::Conversions,
            /*
             * OBJECTIVE-NORMALIZATION-004 — the two words the live account actually sends.
             *
             * `integrations:diagnose` was asked why 71 of 87 campaigns sat unclassified, and it
             * answered with the words themselves: `WEB_CONVERSION` and `WEB_VIEW`. Neither is a
             * guess at Snapchat's vocabulary — they are what this account's campaigns carry in
             * `objective_platform_value`, read back out of production.
             *
             * The table already held `WEBSITE_CONVERSIONS`, which is the older, longer spelling and
             * not what the API returns. One missing letter-for-letter match left every conversion
             * campaign on the account judged by the Unknown family.
             *
             * `WEB_VIEW` is a traffic buy — a swipe to a page — and is mapped to Traffic rather than
             * to Conversions, because a view is not an outcome somebody purchased.
             */
            'WEB_CONVERSION' => CampaignObjective::Conversions,
            'WEB_VIEW' => CampaignObjective::Traffic,
            'CATALOG_SALES' => CampaignObjective::Sales,
        ],

        'x' => [
            'REACH' => CampaignObjective::Reach,
            'ENGAGEMENTS' => CampaignObjective::Engagement,
            'FOLLOWERS' => CampaignObjective::Engagement,
            'VIDEO_VIEWS' => CampaignObjective::VideoViews,
            'PREROLL_VIEWS' => CampaignObjective::VideoViews,
            'WEBSITE_CLICKS' => CampaignObjective::Traffic,
            'APP_INSTALLS' => CampaignObjective::AppInstalls,
            'APP_ENGAGEMENTS' => CampaignObjective::AppInstalls,
            'WEBSITE_CONVERSIONS' => CampaignObjective::Conversions,
        ],

        'linkedin' => [
            'BRAND_AWARENESS' => CampaignObjective::Awareness,
            'VIDEO_VIEW' => CampaignObjective::VideoViews,
            'ENGAGEMENT' => CampaignObjective::Engagement,
            'WEBSITE_VISIT' => CampaignObjective::Traffic,
            'LEAD_GENERATION' => CampaignObjective::Leads,
            'JOB_APPLICANT' => CampaignObjective::Leads,
            'TALENT_LEAD' => CampaignObjective::Leads,
            'WEBSITE_CONVERSION' => CampaignObjective::Conversions,
        ],
    ];

    /**
     * Translate one platform value, or return null when nothing here recognises it.
     *
     * Null is a real answer and the caller must treat it as one: it means the platform said
     * something this product does not understand, which is a prompt for a person, not licence to
     * guess.
     */
    public function resolve(string $provider, ?string $raw): ?CampaignObjective
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $table = self::MAP[$this->normaliseProvider($provider)] ?? null;

        if ($table === null) {
            return null;
        }

        // Platforms are inconsistent about case and separator across API versions and exports —
        // `outcome_sales`, `OUTCOME_SALES` and `Outcome Sales` are all the same objective.
        $key = strtoupper(str_replace([' ', '-'], '_', trim($raw)));

        if (array_key_exists($key, $table)) {
            return $table[$key];
        }

        /*
         * OBJECTIVE-NORMALIZATION-002 — the platform used OUR OWN canonical name.
         *
         * Snapchat's current campaign objective enum includes `SALES`, `AWARENESS`, `TRAFFIC`,
         * `ENGAGEMENT`, `VIDEO_VIEWS`, `LEADS` and `REACH`. The table above was written against the
         * older names — `CATALOG_SALES`, `DRIVE_TRAFFIC_TO_WEBSITE` — so `SALES` resolved to nothing,
         * the resolver declined to classify, and the RAW string was left standing in the canonical
         * column. Every one of this account's campaigns is in that state, which is why objective-aware
         * KPI selection has been off in production: `CampaignObjective::tryFrom('SALES')` fails, so
         * every creative was headlined by the Unknown family regardless of what it was bought to do.
         *
         * Matching against the canonical vocabulary is not a guess and not a fallback heuristic. It
         * fires only when the platform's own string, lowercased, IS one of this product's objective
         * values — an exact match on a closed set. A platform word that means something else, like
         * Google's `advertisingChannelType`, still resolves to nothing and still lands unclassified,
         * which is the behaviour the audit trail depends on.
         *
         * The explicit table stays first and stays authoritative: it is where a platform word that
         * does NOT coincide with ours is translated, and nothing here weakens it.
         */
        return CampaignObjective::tryFrom(strtolower($key));
    }

    /**
     * `google` and `google_ads` are the same platform under two names.
     *
     * They were genuinely drifting apart in the connector registry once already (ADAUDIT-001), and a
     * map keyed on one of them silently classifies nothing for campaigns that arrive under the other.
     */
    private function normaliseProvider(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'google_ads', 'googleads' => 'google',
            'twitter' => 'x',
            default => strtolower(trim($provider)),
        };
    }

    /** @return list<string> the providers this map can translate, for the review checklists */
    public function providers(): array
    {
        return array_keys(self::MAP);
    }

    /** Every value this map understands for one provider — used by the tests and the admin audit. */
    public function knownValues(string $provider): array
    {
        return array_keys(self::MAP[$this->normaliseProvider($provider)] ?? []);
    }
}
