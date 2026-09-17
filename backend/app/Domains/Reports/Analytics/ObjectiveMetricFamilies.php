<?php

declare(strict_types=1);

namespace App\Domains\Reports\Analytics;

use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — the ONE objective → metric-family mapping every report surface reads.
 *
 * ## What a family shows, and nothing else
 *
 * A block for awareness money shows what awareness money buys: reach, impressions, frequency, CPM. It
 * has no CPA, not because the figure is hidden but because the question was never asked of that
 * money — a cost per order over a brand budget is arithmetic on an event nobody was buying. A key
 * outside a family's list is INAPPLICABLE, which is a different fact from UNAVAILABLE (the family
 * asks for it and no provider reported it) and from ZERO (reported, and nothing happened).
 *
 * ## Ratios are rebuilt from sums, never averaged
 *
 * Every derived figure is declared here as numerator ÷ denominator over BASE sums. A family's CPM is
 * its summed spend over its summed impressions; a platform's is the same over that platform's rows.
 * Averaging per-campaign CPMs weights a ten-riyal test campaign equally with the campaign that spent
 * the budget, which is how an average comes to describe money nobody spent.
 *
 * ## Families come from `CampaignObjective::family()`
 *
 * Not restated here. The objective → family decision already exists and the creative ranker reads it;
 * a second copy would be a second answer to «what was this campaign bought for».
 */
final class ObjectiveMetricFamilies
{
    /** The base figures read from `daily_metrics`. Everything else is derived from these sums. */
    public const BASE = [
        'spend', 'impressions', 'reach', 'clicks', 'landing_page_views', 'engagements',
        'video_views', 'leads', 'conversions', 'revenue', 'installs',
    ];

    /** Base figures that are money and obey FX-001's withheld-conversion rule. */
    public const MONEY_BASE = ['spend', 'revenue'];

    /**
     * Derived figures: [numerator, denominator, scale].
     *
     * `frequency` is impressions ÷ reach, the same derivation the KPI cards use. Reach is summed as the
     * platforms report it per day, so the figure inherits that basis — it is not a deduplicated
     * unique reach, and no surface here claims it is.
     *
     * @var array<string, array{0:string, 1:string, 2:float}>
     */
    public const DERIVED = [
        'frequency' => ['impressions', 'reach', 1.0],
        'cpm' => ['spend', 'impressions', 1000.0],
        'ctr' => ['clicks', 'impressions', 1.0],
        'cpc' => ['spend', 'clicks', 1.0],
        'cost_per_lpv' => ['spend', 'landing_page_views', 1.0],
        'engagement_rate' => ['engagements', 'impressions', 1.0],
        'cpe' => ['spend', 'engagements', 1.0],
        'cost_per_view' => ['spend', 'video_views', 1.0],
        'cpl' => ['spend', 'leads', 1.0],
        'cpa' => ['spend', 'conversions', 1.0],
        'roas' => ['revenue', 'spend', 1.0],
        'cpi' => ['spend', 'installs', 1.0],
    ];

    /**
     * Each family's KPI keys, in reading order, and the outcome its contribution is a share of.
     *
     * `conversions` is the sales family's order count because it is the product's one definition of an
     * order — the key `ObjectivePerformance`'s direct CPA divides by. Two definitions of an order would
     * make this block's CPA disagree with the direct-vs-blended block printed beside it.
     *
     * The outcome list is tried in order and the first one reported wins: a sales scope whose platforms
     * return revenue shares revenue; one that does not shares orders. Awareness shares IMPRESSIONS,
     * not reach — per-platform reach cannot be added into a total whose share would mean anything.
     *
     * @var array<string, array{kpis: list<string>, outcomes: list<string>}>
     */
    private const FAMILIES = [
        'awareness' => ['kpis' => ['reach', 'impressions', 'frequency', 'cpm'], 'outcomes' => ['impressions']],
        'traffic' => ['kpis' => ['clicks', 'ctr', 'cpc', 'landing_page_views', 'cost_per_lpv'], 'outcomes' => ['clicks', 'landing_page_views']],
        'engagement' => ['kpis' => ['engagements', 'engagement_rate', 'cpe'], 'outcomes' => ['engagements']],
        'video' => ['kpis' => ['video_views', 'cost_per_view'], 'outcomes' => ['video_views']],
        'leads' => ['kpis' => ['leads', 'cpl'], 'outcomes' => ['leads']],
        'sales' => ['kpis' => ['conversions', 'revenue', 'cpa', 'roas'], 'outcomes' => ['revenue', 'conversions']],
        'app' => ['kpis' => ['installs', 'cpi'], 'outcomes' => ['installs']],
    ];

    /**
     * The smallest volume a figure must rest on before it may be called best or weakest.
     *
     * A creative with three impressions and one click has a CTR of 33%, and it is not the best
     * creative — it is a creative nobody saw. Each ranking metric names the base count its reliability
     * depends on and the minimum below which the row is excluded with a stated reason.
     *
     * These are also the ONLY metrics a ranking may rest on. A volume (reach, leads, installs) orders
     * by budget, so the canonical ranker's volume fallbacks are refused here: no ranking is shown
     * rather than a spend ranking under a performance heading.
     *
     * @var array<string, array{0:string, 1:int}>
     */
    public const MINIMUM_VOLUME = [
        'cpm' => ['impressions', 1000],
        'ctr' => ['impressions', 1000],
        'cpc' => ['clicks', 30],
        'engagement_rate' => ['impressions', 1000],
        'cpe' => ['engagements', 30],
        'cost_per_view' => ['video_views', 300],
        'cpl' => ['leads', 5],
        'cpa' => ['conversions', 5],
        'roas' => ['conversions', 5],
        'cpi' => ['installs', 10],
    ];

    /** The labels a block prints. Short, and the client's words rather than the buyer's. */
    private const LABELS = [
        'spend' => ['ar' => 'الإنفاق', 'en' => 'Spend'],
        'reach' => ['ar' => 'الوصول', 'en' => 'Reach'],
        'impressions' => ['ar' => 'مرات الظهور', 'en' => 'Impressions'],
        'frequency' => ['ar' => 'معدل التكرار', 'en' => 'Frequency'],
        'cpm' => ['ar' => 'تكلفة الألف ظهور', 'en' => 'CPM'],
        'clicks' => ['ar' => 'النقرات', 'en' => 'Clicks'],
        'ctr' => ['ar' => 'نسبة النقر', 'en' => 'CTR'],
        'cpc' => ['ar' => 'تكلفة النقرة', 'en' => 'CPC'],
        'landing_page_views' => ['ar' => 'زيارات صفحة الهبوط', 'en' => 'Landing page views'],
        'cost_per_lpv' => ['ar' => 'تكلفة زيارة الصفحة', 'en' => 'Cost per LPV'],
        'engagements' => ['ar' => 'التفاعلات', 'en' => 'Engagements'],
        'engagement_rate' => ['ar' => 'معدل التفاعل', 'en' => 'Engagement rate'],
        'cpe' => ['ar' => 'تكلفة التفاعل', 'en' => 'CPE'],
        'video_views' => ['ar' => 'المشاهدات', 'en' => 'Video views'],
        'cost_per_view' => ['ar' => 'تكلفة المشاهدة', 'en' => 'Cost per view'],
        'leads' => ['ar' => 'العملاء المحتملون', 'en' => 'Leads'],
        'cpl' => ['ar' => 'تكلفة العميل المحتمل', 'en' => 'CPL'],
        'conversions' => ['ar' => 'المشتريات', 'en' => 'Purchases'],
        'revenue' => ['ar' => 'الإيراد', 'en' => 'Revenue'],
        'cpa' => ['ar' => 'تكلفة الشراء', 'en' => 'CPA'],
        'roas' => ['ar' => 'العائد على الإنفاق', 'en' => 'ROAS'],
        'installs' => ['ar' => 'التثبيتات', 'en' => 'Installs'],
        'cpi' => ['ar' => 'تكلفة التثبيت', 'en' => 'CPI'],
    ];

    /** @return list<string> the families that carry a KPI block, in reading order */
    public static function families(): array
    {
        return array_keys(self::FAMILIES);
    }

    public static function has(string $family): bool
    {
        return isset(self::FAMILIES[$family]);
    }

    /** @return list<string> */
    public static function kpis(string $family): array
    {
        return self::FAMILIES[$family]['kpis'] ?? [];
    }

    /** @return list<string> */
    public static function outcomes(string $family): array
    {
        return self::FAMILIES[$family]['outcomes'] ?? [];
    }

    /**
     * Whether a figure belongs to this family at all. `inapplicable` is not `unavailable`: the first is
     * a question nobody asked of this money, the second is a question no provider answered.
     */
    public static function applicability(string $family, string $key): string
    {
        return in_array($key, self::kpis($family), true) || $key === 'spend' ? 'applicable' : 'inapplicable';
    }

    /** The family a campaign objective's figures are judged under, or null when it has no block. */
    public static function familyOf(ObjectiveFamily $family): ?string
    {
        return self::has($family->value) ? $family->value : null;
    }

    /** @return array{ar:string,en:string} */
    public static function label(string $key): array
    {
        return self::LABELS[$key] ?? ['ar' => $key, 'en' => $key];
    }

    public static function kind(string $key): string
    {
        return match (true) {
            in_array($key, ['spend', 'revenue', 'cpm', 'cpc', 'cost_per_lpv', 'cpe', 'cost_per_view', 'cpl', 'cpa', 'cpi'], true) => 'money',
            in_array($key, ['ctr', 'engagement_rate'], true) => 'rate',
            $key === 'roas' => 'multiplier',
            $key === 'frequency' => 'ratio',
            default => 'count',
        };
    }

    /** Whether a figure is built from a money base, and so inherits its withheld state. */
    public static function dependsOnMoney(string $key): ?string
    {
        if (in_array($key, self::MONEY_BASE, true)) {
            return $key;
        }

        [$num, $den] = self::DERIVED[$key] ?? [null, null];

        foreach ([$num, $den] as $part) {
            if (in_array($part, self::MONEY_BASE, true)) {
                return $part;
            }
        }

        return null;
    }
}
