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
                view: 'summary',
            ),
            new ReportSection(
                key: 'trends',
                titleAr: 'الاتجاهات عبر الفترة',
                titleEn: 'Trends over the period',
                payloadKeys: ['timeseries', 'platform_series'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'platform_comparison',
                titleAr: 'مقارنة المنصات',
                titleEn: 'Platform comparison',
                payloadKeys: ['platforms', 'platform_notes', 'reported_by_platform'],
                slideTypes: ['platform_comparison', 'platform_performance', 'platform_notes', 'platform_screenshot'],
                view: 'platforms',
            ),
            new ReportSection(
                key: 'budget_pacing',
                titleAr: 'الميزانية ووتيرة الصرف',
                titleEn: 'Budget & pacing',
                payloadKeys: ['budget'],
                slideTypes: ['budget'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'funnel',
                titleAr: 'مسار التحويل',
                titleEn: 'Funnel',
                payloadKeys: ['funnel', 'funnel_spend', 'store_funnel'],
                slideTypes: ['funnel'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'content_performance',
                titleAr: 'أداء المحتوى',
                titleEn: 'Content performance',
                payloadKeys: [
                    'ads', 'ads_level', 'ads_groups', 'ads_platform_groups', 'ads_roster', 'ads_weakest', 'ads_reading',
                    'ads_absent_reason', 'top_creatives', 'worst_creatives', 'creatives_in_scope', 'creatives_withheld', 'creatives_hidden', 'creative_level',
                ],
                slideTypes: ['ads', 'top_creatives'],
                view: 'content',
            ),
            /*
             * GA4-INTEGRATION-001 — the client's OWN SITE, measured by their own Analytics.
             *
             * After the paid-media story and before the insights, because that is what it is:
             * context for the campaigns, never a verdict on them. Its revenue is measured under
             * GA4's attribution and the platforms' is measured under theirs, so the two are never
             * added, never divided into one another, and never shown as one number.
             *
             * OFF by default on a client-facing report. A reader shown two revenue figures on one
             * page will add them, and an operator who puts this in front of a client is choosing to
             * explain the difference. That choice is theirs to make deliberately rather than ours to
             * make for them — the same reasoning as the detailed tables and advanced segmentation.
             */
            new ReportSection(
                key: 'site_measurement',
                titleAr: 'قياس موقع العميل',
                titleEn: 'Client site measurement',
                payloadKeys: ['site_measurement'],
                clientDefault: false,
                view: 'dashboard',
            ),
            /*
             * STORE-ANALYTICS-DEPTH-001 §3.1 — the store and its traffic as one category to show or hide, live and snapshot.
             *
             * OFF on a client-facing report until an operator switches it on: no link in existence gains a
             * section because this shipped, and turning it on keeps the link's address and scope.
             */
            new ReportSection(
                key: 'store_analytics',
                titleAr: 'تحليلات المتجر والزيارات',
                titleEn: 'Store & traffic analytics',
                payloadKeys: ['store_analytics'],
                clientDefault: false,
                view: 'dashboard',
            ),
            /*
             * GA4-REPORT-SECTIONS-001 — the store as its own GA4 property measured it, in four parts.
             *
             * Each part is a SHOW/HIDE of its own, and each is ON unless the operator hides it (the
             * Owner's rule: nothing is hidden by default). That does not put a section on any link
             * that exists today: a part is available only where the project has a selected property
             * with figures for the window, and its data is absent from the payload otherwise.
             *
             * GA4's figures are GA4's, under GA4's attribution. The gap part sets them BESIDE what the
             * platforms reported; nothing here adds the two (`GA4-NOT-BLENDED-001`).
             */
            new ReportSection(
                key: 'ga4_overview',
                titleAr: 'زيارات ومبيعات المتجر (GA4)',
                titleEn: 'Store visits & sales (GA4)',
                payloadKeys: ['ga4_overview'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'ga4_acquisition',
                titleAr: 'القنوات والأجهزة والقمع (GA4)',
                titleEn: 'Channels, devices & funnel (GA4)',
                payloadKeys: ['ga4_acquisition'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'ga4_platform_gap',
                titleAr: 'GA4 مقابل المنصات',
                titleEn: 'GA4 vs platforms',
                payloadKeys: ['ga4_platform_gap'],
                view: 'dashboard',
            ),
            new ReportSection(
                key: 'ga4_tables',
                titleAr: 'جداول المتجر (GA4)',
                titleEn: 'Store tables (GA4)',
                payloadKeys: ['ga4_tables'],
                view: 'tables',
            ),
            new ReportSection(
                key: 'recommendations',
                titleAr: 'الملاحظات والتوصيات',
                titleEn: 'Insights & recommendations',
                // REPORT-RECOMMENDATION-BLOCKS-001 — `attention` is the «what needs attention» blocks.
                payloadKeys: ['findings', 'observations', 'recommendations', 'next_steps', 'attention'],
                slideTypes: ['observations', 'recommendations', 'next_steps'],
                view: 'summary',
            ),
            new ReportSection(
                key: 'detailed_tables',
                titleAr: 'الجداول التفصيلية',
                titleEn: 'Detailed tables',
                payloadKeys: ['platforms', 'campaigns', 'ad_sets'],
                slideTypes: ['campaigns'],
                clientDefault: false,
                view: 'tables',
            ),
            /*
             * REPORT-COMPOSITION-CONTROL-001 — «إظهار السجل اليومي», a component of its own.
             *
             * The day-by-day ledger (the Tables view's daily table, the files' daily rows) used to ride
             * on `trends`, so it could not be shown or hidden on its own. It is an independent SHOW/HIDE
             * switch like every display section: on unless the Owner hides it, so no report or link
             * loses a table it had, and nothing is aggregated away by default.
             *
             * It owns `timeseries` jointly with the KPIs and the trend: the series leaves the payload
             * only when none of the three is shown, because a chart drawn from it is the chart itself.
             */
            new ReportSection(
                key: 'daily_records',
                titleAr: 'السجل اليومي',
                titleEn: 'Daily records',
                payloadKeys: ['timeseries'],
                view: 'tables',
            ),
            /*
             * REPORT-ACCOUNT-BREAKDOWN-001 — the ad ACCOUNTS, as their own section.
             *
             * The Owner asks to control accounts as a unit — alongside platforms, campaigns and ads — and a
             * breakdown that rode inside «detailed tables» could be neither switched on for a Standard
             * report nor removed from a Detailed one on its own. It is the Detailed product's depth (a
             * provider → account split), off for everything else by default, and a real switch everywhere.
             */
            new ReportSection(
                key: 'account_breakdown',
                titleAr: 'تفصيل الحسابات الإعلانية',
                titleEn: 'Ad account breakdown',
                payloadKeys: ['accounts'],
                slideTypes: ['accounts'],
                clientDefault: false,
                breakdown: true,
                view: 'tables',
            ),
            new ReportSection(
                key: 'objective_breakdown',
                titleAr: 'التفصيل حسب الهدف',
                titleEn: 'Breakdown by objective',
                // REPORT-OBJECTIVE-ANALYTICS-001 — KPI blocks per objective family, leaders, trend
                // and contribution; the per-path leaders stay for payloads built before it.
                payloadKeys: ['objective_leaders', 'objective_analytics'],
                breakdown: true,
                view: 'dashboard',
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
                payloadKeys: ['objective_performance', 'objective_performance_previous', 'business_streams', 'business_streams_cover_total'],
                slideTypes: ['objective_performance'],
                clientDefault: false,
                breakdown: true,
                view: 'dashboard',
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

        // A ledger with no day in it is not a section.
        $this->availableWhen('daily_records', 'has_days', static fn (SectionContext $c): bool => $c->rows('timeseries') !== []);

        $this->availableWhen('platform_comparison', 'has_platforms', static fn (SectionContext $c): bool => $c->rows('platforms') !== []);

        $this->availableWhen('budget_pacing', 'has_a_budget', static fn (SectionContext $c): bool => array_filter(
            $c->rows('budget'),
            static fn (array $row): bool => is_numeric($row['budget'] ?? null) && (float) $row['budget'] > 0,
        ) !== []);

        // A funnel of stages no platform reported is not a funnel; the store funnel stands on its own.
        $this->availableWhen('account_breakdown', 'has_accounts', static fn (SectionContext $c): bool => $c->rows('accounts') !== []);

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

        /*
         * A property somebody selected, with days actually read in this window.
         *
         * `build()` already returns null for «no property» and for «nothing read yet» — two different
         * facts that are both «no section», and neither of which is a zero.
         */
        $this->availableWhen('site_measurement', 'has_measured_days', static fn (SectionContext $c): bool => is_array($c->value('site_measurement')));

        $this->availableWhen('store_analytics', 'has_store_or_site_figures', static fn (SectionContext $c): bool => is_array($c->value('store_analytics')));

        // GA4-REPORT-SECTIONS-001 — a part with nothing measured for the window is not a section, and never a zero.
        foreach (['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables'] as $ga4) {
            $this->availableWhen($ga4, 'has_ga4_figures', static fn (SectionContext $c): bool => is_array($c->value($ga4)));
        }

        $this->availableWhen('recommendations', 'has_an_insight', static fn (SectionContext $c): bool => $c->rows('recommendations') !== [] || $c->rows('findings') !== [] || $c->rows('observations') !== [] || $c->rows('next_steps') !== [] || $c->rows('attention') !== []);

        $this->availableWhen('detailed_tables', 'has_rows', static fn (SectionContext $c): bool => $c->rows('platforms') !== [] || $c->rows('campaigns') !== []);

        $this->availableWhen('objective_breakdown', 'has_paths', static function (SectionContext $c): bool {
            $leaders = $c->value('objective_leaders');
            $analytics = $c->value('objective_analytics');

            return (is_array($leaders) && is_array($leaders['paths'] ?? null) && $leaders['paths'] !== [])
                || (is_array($analytics) && is_array($analytics['families'] ?? null) && $analytics['families'] !== []);
        });

        $this->availableWhen('advanced_segmentation', 'has_segments', static function (SectionContext $c): bool {
            $split = $c->value('objective_performance');

            // A client's segmentation is the operator's business streams and nothing else.
            if ($c->isClientFacing()) {
                return $c->rows('business_streams') !== [];
            }

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
