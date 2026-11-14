<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * OBJECTIVE-PATHS-001 — the objective of ONE PATH of a campaign: its ad set / ad squad / ad group.
 *
 * ## Why the campaign objective is not enough
 *
 * One campaign can buy two different things. 1KCOFFEE runs a Snapchat SALES campaign whose squads
 * split into an online-store path (optimised for purchases) and a branches path (optimised for page
 * visits). Classifying the whole campaign as sales divides the branches' spend into the store's
 * ROAS; classifying it as visits ignores the sales. Each path is judged by what ITS delivery was
 * optimised to buy — the ad set's own optimisation goal.
 *
 * ## The rule
 *
 * A goal that names one outcome unambiguously decides the path's family. A goal that does not —
 * Meta's OFFSITE_CONVERSIONS optimises whatever pixel event was chosen, a purchase or a lead alike;
 * an empty goal (Google ad groups carry none; TikTok's enum is not in a public document this product
 * could cite) — inherits the campaign's own family. Nothing is guessed from a name.
 *
 * ## Sources (each value below is from the platform's own reference)
 *
 *   - Meta: developers.facebook.com/docs/marketing-api/reference/ad-campaign («Ad Set»),
 *     `optimization_goal` enum.
 *   - Snapchat: developers.snap.com/api/marketing-api/Ads-API/ad-squads, `optimization_goal` enum,
 *     and its CHAT_FEED tables pairing goals with `objective_v2_type` (PIXEL_PURCHASE → SALES;
 *     LEAD_FORM_SUBMISSIONS → LEAD_GENERATION; STORY_OPENS → AWARENESS_AND_ENGAGEMENT). LANDING_PAGE_VIEW
 *     and PIXEL_PAGE_VIEW appear under SALES and TRAFFIC alike there — a squad optimised for them buys
 *     a visit whatever its campaign is called, which is exactly the branches path.
 */
final class PathObjective
{
    /** @var array<string, array<string, ObjectiveFamily>> provider => goal => family */
    private const GOALS = [
        'meta' => [
            'VALUE' => ObjectiveFamily::Sales,
            'MESSAGING_PURCHASE_CONVERSION' => ObjectiveFamily::Sales,
            'LANDING_PAGE_VIEWS' => ObjectiveFamily::Traffic,
            'LINK_CLICKS' => ObjectiveFamily::Traffic,
            'REACH' => ObjectiveFamily::Awareness,
            'IMPRESSIONS' => ObjectiveFamily::Awareness,
            'AD_RECALL_LIFT' => ObjectiveFamily::Awareness,
            'THRUPLAY' => ObjectiveFamily::Video,
            'POST_ENGAGEMENT' => ObjectiveFamily::Engagement,
            'PAGE_LIKES' => ObjectiveFamily::Engagement,
            'LEAD_GENERATION' => ObjectiveFamily::Leads,
            'QUALITY_LEAD' => ObjectiveFamily::Leads,
            'APP_INSTALLS' => ObjectiveFamily::App,
        ],
        'snapchat' => [
            'PIXEL_PURCHASE' => ObjectiveFamily::Sales,
            'PIXEL_ADD_TO_CART' => ObjectiveFamily::Sales,
            'LANDING_PAGE_VIEW' => ObjectiveFamily::Traffic,
            'PIXEL_PAGE_VIEW' => ObjectiveFamily::Traffic,
            'SWIPES' => ObjectiveFamily::Traffic,
            'IMPRESSIONS' => ObjectiveFamily::Awareness,
            'STORY_OPENS' => ObjectiveFamily::Awareness,
            'VIDEO_VIEWS' => ObjectiveFamily::Video,
            'VIDEO_VIEWS_15_SEC' => ObjectiveFamily::Video,
            'LEAD_FORM_SUBMISSIONS' => ObjectiveFamily::Leads,
            'APP_INSTALLS' => ObjectiveFamily::App,
        ],
    ];

    /**
     * The family a path is judged by.
     *
     * An Unknown campaign stays Unknown whatever its ad sets say: a campaign nobody classified is a
     * question for a person, and letting a goal answer it here would classify the campaign through
     * the back door the objective resolver deliberately keeps shut.
     */
    public static function familyOf(string $provider, ?string $goal, ObjectiveFamily $campaignFamily): ObjectiveFamily
    {
        if ($campaignFamily === ObjectiveFamily::Unknown) {
            return ObjectiveFamily::Unknown;
        }

        $key = strtoupper(trim((string) $goal));

        return self::GOALS[self::provider($provider)][$key] ?? $campaignFamily;
    }

    /** @return list<string> the goals a provider's table decides, for tests and audits */
    public static function knownGoals(string $provider): array
    {
        return array_keys(self::GOALS[self::provider($provider)] ?? []);
    }

    private static function provider(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'facebook', 'instagram' => 'meta',
            default => strtolower(trim($provider)),
        };
    }
}
