<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Enums;

/**
 * Business objective of a unified campaign (REPORT-OBJECTIVE-002).
 *
 * Platform-specific objectives are mapped onto these when importing external campaigns; an
 * unrecognised one becomes {@see self::Other} rather than a guess. **The campaign's NAME never
 * decides this.** A campaign called «Ramadan Sale» may be a reach buy, and reading the objective off
 * the name is how a brand campaign's spend ends up in a cost-per-order figure.
 *
 * The first eight cases predate the objective-based reports and are UNCHANGED — their values are
 * written in every `unified_campaigns` row that exists, and renaming one would silently reclassify
 * live campaigns. The six that follow complete the canonical set the reporting contract names.
 *
 * Each objective belongs to exactly one {@see MarketingPath}, and that mapping is the whole point:
 * it is what keeps awareness spend out of a sales CPA.
 */
enum CampaignObjective: string
{
    case Awareness = 'awareness';
    case Traffic = 'traffic';
    case Engagement = 'engagement';
    case Leads = 'leads';
    case AppInstalls = 'app_installs';
    case Sales = 'sales';
    case Conversions = 'conversions';
    case Other = 'other';

    // Added for REPORT-OBJECTIVE-002. Platforms distinguish these and the product was folding them
    // into their neighbours, which loses the distinction a report is supposed to draw.
    case Reach = 'reach';
    case VideoViews = 'video_views';
    case LandingPageViews = 'landing_page_views';
    case AddToCart = 'add_to_cart';
    case Purchases = 'purchases';
    case StoreVisits = 'store_visits';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }

    /**
     * Which path this objective's money belongs to.
     *
     * `Other` resolves to Awareness deliberately, and it is the only judgement call in the table.
     * An unclassified campaign has to sit somewhere, and the choice is between excluding its spend
     * from the sales CPA (understating what the objective is) or including it (overstating what an
     * order costs). Only the second can mislead a budget decision, so an unknown objective is
     * treated as not-a-sales-campaign until somebody says otherwise — and `objective_source` records
     * that nobody has.
     */
    /**
     * OBJECTIVE-NORMALIZATION-001 — which family's KPIs this objective is judged by.
     *
     * Distinct from {@see self::path()}, and deliberately so. `path()` answers a MONEY question —
     * whose CPA this spend may land in — and three buckets are the right number for that. This
     * answers «which figures are the verdict», where three is far too few: `Leads` and `AppInstalls`
     * share the conversion path, so both were headlined with `revenue`, `roas` and `aov`, figures
     * neither campaign was bought to produce and neither platform will ever report for them.
     *
     * `Conversions` maps to Sales rather than to a family of its own: a conversion campaign IS
     * judged on cost per result and value, which is what the sales set says.
     */
    public function family(): ObjectiveFamily
    {
        return match ($this) {
            self::Awareness, self::Reach => ObjectiveFamily::Awareness,
            self::Traffic, self::LandingPageViews => ObjectiveFamily::Traffic,
            self::Engagement => ObjectiveFamily::Engagement,
            self::VideoViews => ObjectiveFamily::Video,
            self::Leads => ObjectiveFamily::Leads,
            self::Sales, self::Conversions, self::Purchases, self::AddToCart => ObjectiveFamily::Sales,
            self::AppInstalls => ObjectiveFamily::App,
            /*
             * `StoreVisits` is a footfall objective. It reports neither online revenue nor leads,
             * so the sales set would headline it with figures that are structurally absent — and
             * «Unclassified» showing spend, impressions and reach is true of it.
             */
            self::StoreVisits, self::Other => ObjectiveFamily::Unknown,
        };
    }

    public function path(): MarketingPath
    {
        return match ($this) {
            self::Awareness, self::Reach, self::VideoViews, self::Engagement, self::Other => MarketingPath::Awareness,
            self::Traffic, self::LandingPageViews, self::StoreVisits => MarketingPath::Traffic,
            self::Leads, self::AppInstalls, self::AddToCart, self::Sales,
            self::Conversions, self::Purchases => MarketingPath::Conversion,
        };
    }

    /**
     * Whether a result from this campaign is a SALE rather than some other conversion.
     *
     * Leads and app installs are conversions and are not revenue, so they belong in the conversion
     * path and NOT in ROAS. Counting a lead campaign's spend against a store's revenue would flatter
     * the return by the whole cost of the lead programme.
     */
    public function isSales(): bool
    {
        return in_array($this, [self::Sales, self::Purchases, self::Conversions, self::AddToCart], true);
    }

    public function labels(): array
    {
        return match ($this) {
            self::Awareness => ['ar' => 'الوعي', 'en' => 'Awareness'],
            self::Reach => ['ar' => 'الوصول', 'en' => 'Reach'],
            self::VideoViews => ['ar' => 'مشاهدات الفيديو', 'en' => 'Video views'],
            self::Engagement => ['ar' => 'التفاعل', 'en' => 'Engagement'],
            self::Traffic => ['ar' => 'الزيارات', 'en' => 'Traffic'],
            self::LandingPageViews => ['ar' => 'زيارات صفحة الهبوط', 'en' => 'Landing page views'],
            self::StoreVisits => ['ar' => 'زيارات المتجر', 'en' => 'Store visits'],
            self::Leads => ['ar' => 'العملاء المحتملون', 'en' => 'Lead generation'],
            self::AppInstalls => ['ar' => 'تثبيت التطبيق', 'en' => 'App install'],
            self::AddToCart => ['ar' => 'الإضافة للسلة', 'en' => 'Add to cart'],
            self::Conversions => ['ar' => 'التحويلات', 'en' => 'Conversion'],
            self::Sales => ['ar' => 'المبيعات', 'en' => 'Sales'],
            self::Purchases => ['ar' => 'المشتريات', 'en' => 'Purchases'],
            self::Other => ['ar' => 'هدف مخصّص', 'en' => 'Custom objective'],
        };
    }

    /** The catalogue an interface offers, with the path each choice puts the money in. */
    public static function catalogue(): array
    {
        return array_map(static fn (self $c) => [
            'value' => $c->value,
            'label_ar' => $c->labels()['ar'],
            'label_en' => $c->labels()['en'],
            'path' => $c->path()->value,
            'is_sales' => $c->isSales(),
        ], self::cases());
    }
}
