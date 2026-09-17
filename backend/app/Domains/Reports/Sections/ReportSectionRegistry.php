<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use Closure;
use InvalidArgumentException;

/**
 * The one list of what a client report can contain — REPORT-SECTION-MODEL-001.
 *
 * ## Why one list
 *
 * Before this, «which sections does this report have» had four answers: the per-link flags
 * (`ShareSections`), the form (`ReportComposition`), the slide list the exporter reads, and the
 * outline (`ReportStructure`). Live, the shared link, the print route and the export each consulted a
 * different subset, so the same report could carry a budget block on the page and not in the PDF.
 * Every surface now resolves its sections through this registry and `ReportSectionResolver`, so the
 * set cannot differ between them.
 *
 * ## Order
 *
 * The order is the reading order the Owner fixed: KPIs → charts → comparisons → content →
 * insight/action, with the tables and the optional breakdowns after the report proper.
 *
 * ## Extending it
 *
 * Other lanes do not edit the definitions to say when a section is supported or available. They
 * register a predicate:
 *
 *     $registry->supportWhen('funnel', 'store_connected', fn (SectionContext $c): bool => …);
 *     $registry->availableWhen('trends', 'two_points', fn (SectionContext $c): bool => …);
 *
 * Every predicate of a kind must pass (a conjunction), so a predicate can only ever take a section
 * away — no lane can make a section appear that another lane's evidence says is unsupported.
 */
final class ReportSectionRegistry
{
    /** @var array<string, ReportSection> */
    private array $sections = [];

    /** @var array<string, array<string, Closure(SectionContext): bool>> */
    private array $support = [];

    /** @var array<string, array<string, Closure(SectionContext): bool>> */
    private array $availability = [];

    public function __construct()
    {
        foreach (self::definitions() as $section) {
            $this->sections[$section->key] = $section;
        }

        $this->registerBuiltInPredicates();
    }

    /** @return list<ReportSection> */
    private static function definitions(): array
    {
        return [
            new ReportSection(
                key: 'kpis',
                titleAr: 'المؤشرات الرئيسية',
                titleEn: 'Key metrics',
                payloadKeys: ['kpis', 'totals', 'delta', 'deltas', 'previous', 'metrics', 'metric_set', 'reported', 'conversions_basis', 'timeseries'],
                slideTypes: ['comparison'],
            ),
            new ReportSection(
                key: 'trends',
                titleAr: 'الاتجاهات عبر الفترة',
                titleEn: 'Trends over the period',
                payloadKeys: ['timeseries', 'platform_series'],
            ),
            new ReportSection(
                key: 'platform_comparison',
                titleAr: 'مقارنة المنصات',
                titleEn: 'Platform comparison',
                payloadKeys: ['platforms', 'platform_notes', 'reported_by_platform'],
                slideTypes: ['platform_comparison', 'platform_performance', 'platform_notes', 'platform_screenshot'],
            ),
            new ReportSection(
                key: 'budget_pacing',
                titleAr: 'الميزانية ووتيرة الصرف',
                titleEn: 'Budget & pacing',
                payloadKeys: ['budget'],
                slideTypes: ['budget'],
            ),
            new ReportSection(
                key: 'funnel',
                titleAr: 'مسار التحويل',
                titleEn: 'Funnel',
                payloadKeys: ['funnel', 'funnel_spend', 'store_funnel'],
                slideTypes: ['funnel'],
            ),
            new ReportSection(
                key: 'content_performance',
                titleAr: 'أداء المحتوى',
                titleEn: 'Content performance',
                payloadKeys: [
                    'ads', 'ads_level', 'ads_groups', 'ads_platform_groups', 'ads_roster', 'ads_weakest', 'ads_reading',
                    'ads_absent_reason', 'top_creatives', 'worst_creatives', 'creatives_in_scope', 'creatives_withheld', 'creative_level',
                ],
                slideTypes: ['ads', 'top_creatives'],
            ),
            new ReportSection(
                key: 'recommendations',
                titleAr: 'الملاحظات والتوصيات',
                titleEn: 'Insights & recommendations',
                payloadKeys: ['findings', 'observations', 'recommendations', 'next_steps'],
                slideTypes: ['observations', 'recommendations', 'next_steps'],
            ),
            new ReportSection(
                key: 'detailed_tables',
                titleAr: 'الجداول التفصيلية',
                titleEn: 'Detailed tables',
                payloadKeys: ['platforms', 'campaigns', 'ad_sets'],
                slideTypes: ['campaigns'],
                clientDefault: false,
            ),
            new ReportSection(
                key: 'objective_breakdown',
                titleAr: 'التفصيل حسب الهدف',
                titleEn: 'Breakdown by objective',
                payloadKeys: ['objective_leaders'],
                breakdown: true,
            ),
            /*
             * Advanced segmentation is OFF for every client-facing report unless an operator turns it
             * on. It is where a split by business stream lives — never on the main report, whose
             * KPIs stay the truthful overall figures.
             */
            new ReportSection(
                key: 'advanced_segmentation',
                titleAr: 'التقسيم المتقدم',
                titleEn: 'Advanced segmentation',
                payloadKeys: ['objective_performance', 'objective_performance_previous', 'business_streams'],
                slideTypes: ['objective_performance'],
                clientDefault: false,
                breakdown: true,
            ),
        ];
    }

    private function registerBuiltInPredicates(): void
    {
        $this->availableWhen('kpis', 'has_totals', static function (SectionContext $c): bool {
            $totals = $c->value('totals', 'kpis');

            return is_array($totals) && array_filter($totals, static fn ($v): bool => $v !== null) !== [];
        });

        // A trend is a line; one point is not one.
        $this->availableWhen('trends', 'two_points', static fn (SectionContext $c): bool => count($c->rows('timeseries')) >= 2);

        $this->availableWhen('platform_comparison', 'has_platforms', static fn (SectionContext $c): bool => $c->rows('platforms') !== []);

        $this->availableWhen('budget_pacing', 'has_a_budget', static fn (SectionContext $c): bool => array_filter(
            $c->rows('budget'),
            static fn (array $row): bool => is_numeric($row['budget'] ?? null) && (float) $row['budget'] > 0,
        ) !== []);

        // A funnel of stages no platform reported is not a funnel; the store funnel stands on its own.
        $this->availableWhen('funnel', 'has_a_reported_stage', static fn (SectionContext $c): bool => array_filter(
            $c->rows('funnel'),
            static fn (array $stage): bool => ($stage['reported'] ?? false) === true,
        ) !== [] || is_array($c->value('store_funnel')));

        /*
         * «No metric ranks ads honestly for this objective» with nothing else to list is the objective
         * not supporting the section, which is a different fact from «no ad ran» — so it is a SUPPORT
         * predicate. Where the unranked roster exists, the section still has something true to show.
         */
        $this->supportWhen('content_performance', 'objective_has_a_ranking_metric', static fn (SectionContext $c): bool => $c->value('ads_absent_reason') !== 'no_rankable_metric_for_this_objective'
            // The ranking is unsupported, but the roster of what ran still stands on its own.
            || $c->rows('ads_roster') !== []);
        $this->availableWhen('content_performance', 'has_content', static fn (SectionContext $c): bool => $c->rows('ads') !== [] || $c->rows('ads_roster') !== []
            // A summary withholds the list and states the count; the count alone is still the section.
            || (int) ($c->value('creatives_in_scope') ?? 0) > 0);

        $this->availableWhen('recommendations', 'has_an_insight', static fn (SectionContext $c): bool => $c->rows('recommendations') !== [] || $c->rows('findings') !== [] || $c->rows('observations') !== [] || $c->rows('next_steps') !== []);

        $this->availableWhen('detailed_tables', 'has_rows', static fn (SectionContext $c): bool => $c->rows('platforms') !== [] || $c->rows('campaigns') !== []);

        $this->availableWhen('objective_breakdown', 'has_paths', static function (SectionContext $c): bool {
            $leaders = $c->value('objective_leaders');

            return is_array($leaders) && is_array($leaders['paths'] ?? null) && $leaders['paths'] !== [];
        });

        $this->availableWhen('advanced_segmentation', 'has_segments', static function (SectionContext $c): bool {
            $split = $c->value('objective_performance');

            return $c->rows('business_streams') !== []
                || (is_array($split) && is_array($split['paths'] ?? null) && $split['paths'] !== []);
        });
    }

    /** @param Closure(SectionContext): bool $predicate */
    public function supportWhen(string $section, string $name, Closure $predicate): void
    {
        $this->assertKnown($section);
        $this->support[$section][$name] = $predicate;
    }

    /** @param Closure(SectionContext): bool $predicate */
    public function availableWhen(string $section, string $name, Closure $predicate): void
    {
        $this->assertKnown($section);
        $this->availability[$section][$name] = $predicate;
    }

    /** @return array<string, Closure(SectionContext): bool> */
    public function supportPredicates(string $section): array
    {
        return $this->support[$section] ?? [];
    }

    /** @return array<string, Closure(SectionContext): bool> */
    public function availabilityPredicates(string $section): array
    {
        return $this->availability[$section] ?? [];
    }

    /** @return list<ReportSection> */
    public function all(): array
    {
        return array_values($this->sections);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->sections);
    }

    public function has(string $key): bool
    {
        return isset($this->sections[$key]);
    }

    public function get(string $key): ReportSection
    {
        $this->assertKnown($key);

        return $this->sections[$key];
    }

    private function assertKnown(string $key): void
    {
        if (! isset($this->sections[$key])) {
            throw new InvalidArgumentException("Unknown report section: {$key}");
        }
    }
}
