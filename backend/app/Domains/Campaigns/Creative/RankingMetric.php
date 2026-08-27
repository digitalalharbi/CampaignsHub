<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Creative;

use App\Domains\Campaigns\Enums\ObjectiveFamily;
use InvalidArgumentException;

/**
 * The one registry of what a metric means for ranking — CREATIVE-RANK-001.
 *
 * Three services ranked creatives, each with its own `usort`, its own objective handling and its own
 * idea of which metrics exist: `CreativeRankingService` knew seven, `CreativePulse` six,
 * `DigestCreatives` five. `leads` existed in exactly one of them and `cpl` in none — so neither the
 * Pulse nor the email could rank a lead campaign by the thing it was bought for, and «best creative»
 * was a different question depending on which screen asked it.
 *
 * This registry answers it once. Everything that ranks a creative reads from here.
 *
 * ## Fail closed on an unknown metric
 *
 * {@see of()} throws for anything not declared. The alternative — defaulting to higher-is-better —
 * is how a rising cost-per-lead comes to be printed as an improvement, which is worse than an error
 * because nobody sees it. A metric that should be rankable is declared here, deliberately, once.
 *
 * ## Money is a separate axis from direction
 *
 * `isMoney` decides whether the money contract's comparability rules apply — a scope whose spend is
 * partial or in mixed currencies has no comparable cost-per-result, and the creative is EXCLUDED
 * from that ranking with a stated reason rather than ranked on half a figure.
 */
final class RankingMetric
{
    private function __construct(
        public readonly string $key,
        public readonly RankingDirection $direction,
        public readonly bool $isMoney,
        public readonly string $labelAr,
        public readonly string $labelEn,
    ) {}

    /**
     * Every metric that may order a creative list.
     *
     * @return array<string, array{RankingDirection, bool, string, string}>
     */
    private static function registry(): array
    {
        $up = RankingDirection::HigherIsBetter;
        $down = RankingDirection::LowerIsBetter;

        return [
            // Awareness
            'impressions' => [$up, false, 'مرات الظهور', 'Impressions'],
            'reach' => [$up, false, 'الوصول', 'Reach'],
            'cpm' => [$down, true, 'تكلفة الألف ظهور', 'CPM'],
            /*
             * Frequency has no direction, and that is why it is absent.
             *
             * Rising frequency is fatigue on a prospecting campaign and reach working as intended on
             * a retargeting one. A registry entry would have to pick one, and picking either makes
             * half the campaigns rank backwards. It is a diagnostic, shown beside a ranking, never
             * the thing that orders it.
             */

            // Traffic
            'clicks' => [$up, false, 'النقرات', 'Clicks'],
            'landing_page_views' => [$up, false, 'زيارات الصفحة', 'Landing page views'],
            'ctr' => [$up, false, 'نسبة النقر', 'CTR'],
            'cpc' => [$down, true, 'تكلفة النقرة', 'CPC'],

            // Engagement
            'engagements' => [$up, false, 'التفاعلات', 'Engagements'],
            'engagement_rate' => [$up, false, 'معدل التفاعل', 'Engagement rate'],
            'cpe' => [$down, true, 'تكلفة التفاعل', 'CPE'],

            // Video
            'video_views' => [$up, false, 'المشاهدات', 'Video views'],
            'video_completions' => [$up, false, 'المشاهدات المكتملة', 'Completions'],
            'video_completion_rate' => [$up, false, 'معدل الإكمال', 'Completion rate'],
            'cost_per_view' => [$down, true, 'تكلفة المشاهدة', 'Cost per view'],

            // Leads
            'leads' => [$up, false, 'العملاء المحتملون', 'Leads'],
            'qualified_leads' => [$up, false, 'المؤهلون', 'Qualified leads'],
            'cpl' => [$down, true, 'تكلفة العميل المحتمل', 'CPL'],
            'conversion_rate' => [$up, false, 'معدل التحويل', 'Conversion rate'],

            // Sales
            'purchases' => [$up, false, 'المشتريات', 'Purchases'],
            'conversions' => [$up, false, 'التحويلات', 'Conversions'],
            'revenue' => [$up, true, 'الإيراد', 'Revenue'],
            'roas' => [$up, false, 'العائد على الإنفاق', 'ROAS'],
            'cpa' => [$down, true, 'تكلفة النتيجة', 'CPA'],
            'aov' => [$up, true, 'متوسط قيمة الطلب', 'AOV'],

            // App
            'installs' => [$up, false, 'التثبيتات', 'Installs'],
            'registrations' => [$up, false, 'التسجيلات', 'Registrations'],
            'in_app_events' => [$up, false, 'أحداث داخل التطبيق', 'In-app events'],
            'cpi' => [$down, true, 'تكلفة التثبيت', 'CPI'],

            // Operational — orders a list, never judges performance on its own.
            'spend' => [$up, true, 'الإنفاق', 'Spend'],
        ];
    }

    /** @throws InvalidArgumentException when nobody has declared what «better» means for this metric */
    public static function of(string $key): self
    {
        $row = self::registry()[$key] ?? null;

        if ($row === null) {
            throw new InvalidArgumentException(
                "No ranking direction is declared for '{$key}'. Declare it in RankingMetric::registry() "
                .'rather than letting it default — an undeclared cost metric ranks backwards silently.'
            );
        }

        return new self($key, $row[0], $row[1], $row[2], $row[3]);
    }

    public static function isRankable(string $key): bool
    {
        return isset(self::registry()[$key]);
    }

    /**
     * What a creative bought for this objective should be judged on.
     *
     * The primary is the verdict; the secondaries are the figures that make the verdict readable. A
     * metric is used because the objective calls for it, never because it happens to be populated —
     * ranking an awareness creative by ROAS is arithmetic on an event nobody was buying.
     *
     * `Unknown` has no primary deliberately: a creative whose objective was never classified has no
     * verdict to give, and inventing one is how «best creative» stops meaning anything.
     *
     * @return array{primary: ?string, secondary: list<string>}
     */
    public static function forObjective(ObjectiveFamily $family): array
    {
        return match ($family) {
            /*
             * Awareness ranks on COST, not on volume.
             *
             * `reach` was the first choice here and it is wrong for ranking a creative: the creative
             * that reached the most people is usually the one that was given the most budget, so a
             * reach-ordered list is a spend-ordered list wearing a different label. CPM answers the
             * question a creative comparison is actually asking — which of these buys attention most
             * cheaply — and it is what `CreativeRankingService` already ranked awareness by, with a
             * test pinning it. Reach and impressions stay as the volume context beside the verdict.
             */
            ObjectiveFamily::Awareness => ['primary' => 'cpm', 'secondary' => ['reach', 'impressions', 'frequency']],
            ObjectiveFamily::Traffic => ['primary' => 'ctr', 'secondary' => ['clicks', 'cpc', 'landing_page_views']],
            ObjectiveFamily::Engagement => ['primary' => 'engagement_rate', 'secondary' => ['engagements', 'cpe']],
            // Same reasoning: cost per view is the efficiency lens, completion rate the quality one
            // beside it. A view count on its own ranks by budget.
            ObjectiveFamily::Video => ['primary' => 'cost_per_view', 'secondary' => ['video_views', 'video_completion_rate', 'video_completions']],
            ObjectiveFamily::Leads => ['primary' => 'cpl', 'secondary' => ['leads', 'conversion_rate']],
            ObjectiveFamily::Sales => ['primary' => 'roas', 'secondary' => ['purchases', 'cpa', 'revenue', 'aov']],
            ObjectiveFamily::App => ['primary' => 'cpi', 'secondary' => ['installs', 'registrations', 'in_app_events']],
            ObjectiveFamily::Unknown => ['primary' => null, 'secondary' => ['spend', 'impressions', 'clicks']],
        };
    }
}
