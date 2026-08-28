<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * OBJECTIVE-NORMALIZATION-001 — the canonical families a campaign's KPIs are chosen by.
 *
 * ## Why this sits between the objective and the marketing path
 *
 * {@see MarketingPath} has three cases and answers a MONEY question: whose CPA may this spend land
 * in. That is exactly right for keeping brand budget out of a cost per order, and nothing here
 * changes it.
 *
 * It was also deciding which KPIs to show, and for that three buckets are too few. `Leads` and
 * `AppInstalls` both sit on the conversion path, so a lead-generation campaign was headlined with
 * `revenue`, `roas` and `aov` — figures it was never bought to produce and will never report. An
 * app-install campaign got the same. The requirement puts it plainly: a sales campaign must not be
 * judged primarily on CTR, and an awareness campaign must not be judged on ROAS. The inverse is the
 * failure that was actually shipping.
 *
 * ## The provider's own objective is not replaced
 *
 * `PlatformObjectiveMap` keeps the platform's raw string and leaves `objective_source` unset when it
 * cannot classify one. This adds a layer above that mapping; it removes nothing, and an objective
 * nobody has classified lands in {@see self::Unknown} rather than being guessed into a family.
 */
enum ObjectiveFamily: string
{
    case Awareness = 'awareness';
    case Traffic = 'traffic';
    case Engagement = 'engagement';
    case Video = 'video';
    case Leads = 'leads';
    case Sales = 'sales';
    case App = 'app';
    case Unknown = 'unknown';

    /**
     * The metrics that mean something for this family, most important first.
     *
     * Ordered, not merely selected: the first is what a card leads with, and «which number is the
     * verdict» is the whole question an objective answers. Every key here is one the metric
     * catalogue can actually produce — a headline metric the pipeline cannot supply would render as
     * «not reported» in the most prominent position on the card.
     *
     * `spend` leads every family because it is the one figure that is always the question, whatever
     * the campaign was bought to do.
     *
     * @return list<string>
     */
    /**
     * The customer-facing objective this family rolls up into — ANALYTICS-OBJECTIVE-SYSTEM-001.
     *
     * Eight families, five choices. Awareness, Engagement and Video are one question measured three
     * ways depending on what a platform publishes, so offering them as separate primary choices asked
     * a question with no correct answer. The rest each buy a different outcome and stay apart.
     *
     * The grouping lives HERE rather than in `CanonicalObjective` because this enum is what the metric
     * engines already reason about; putting it anywhere else would make the roll-up a second opinion
     * about the same fact.
     */
    public function canonical(): CanonicalObjective
    {
        return match ($this) {
            self::Awareness, self::Engagement, self::Video => CanonicalObjective::AwarenessEngagement,
            self::Traffic => CanonicalObjective::Traffic,
            self::Leads => CanonicalObjective::Leads,
            self::App => CanonicalObjective::AppPromotion,
            self::Sales => CanonicalObjective::Sales,
            self::Unknown => CanonicalObjective::Unknown,
        };
    }

    public function headlineMetrics(): array
    {
        return match ($this) {
            self::Awareness => ['spend', 'impressions', 'reach', 'frequency', 'cpm'],
            self::Traffic => ['spend', 'clicks', 'ctr', 'cpc', 'landing_page_views', 'cost_per_lpv'],
            // Engagements and their cost — NOT clicks, which is a different thing a person did.
            self::Engagement => ['spend', 'engagements', 'engagement_rate', 'cpe', 'impressions'],
            // A video is judged on whether it was WATCHED, not on whether it was clicked.
            self::Video => ['spend', 'video_views', 'completion_rate', 'cost_per_view', 'view_rate', 'impressions'],
            // CPL is not CPA: a lead is not a customer, and conflating them prices the wrong thing.
            self::Leads => ['spend', 'leads', 'cpl', 'conversion_rate', 'clicks'],
            self::Sales => ['spend', 'orders', 'cpa', 'revenue', 'roas', 'conversion_rate', 'aov'],
            self::App => ['spend', 'installs', 'cpi', 'registrations', 'in_app_events'],
            /*
             * An unclassified campaign gets the figures that are true of every campaign.
             *
             * Not the sales set: `Other` sits on the awareness path precisely so unclassified spend
             * never reaches a cost per order, and headlining it with ROAS would reintroduce through
             * the card what the path mapping refuses at the total.
             */
            self::Unknown => ['spend', 'impressions', 'clicks', 'ctr', 'cpm'],
        };
    }

    /** @return array{ar:string,en:string} */
    public function label(): array
    {
        return match ($this) {
            self::Awareness => ['ar' => 'الوعي', 'en' => 'Awareness'],
            self::Traffic => ['ar' => 'الزيارات', 'en' => 'Traffic'],
            self::Engagement => ['ar' => 'التفاعل', 'en' => 'Engagement'],
            self::Video => ['ar' => 'المشاهدات', 'en' => 'Video'],
            self::Leads => ['ar' => 'العملاء المحتملون', 'en' => 'Leads'],
            self::Sales => ['ar' => 'المبيعات', 'en' => 'Sales'],
            self::App => ['ar' => 'التطبيق', 'en' => 'App'],
            self::Unknown => ['ar' => 'غير مصنَّف', 'en' => 'Unclassified'],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $f) => $f->value, self::cases());
    }
}
