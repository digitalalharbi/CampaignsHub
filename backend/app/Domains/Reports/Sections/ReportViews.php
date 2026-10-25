<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * The MAIN sections of a detailed report — REPORT-COMPOSITION-CONTROL-001 (Owner defect 106).
 *
 * The Owner controls a report on two levels: which main sections it has (Summary, Performance
 * dashboard, Platforms, Content, Tables — each a tab of the detailed live link) and which components
 * live inside them (the registry's sections: KPI cards, charts, funnel, platform comparison, daily
 * records…). This class is the first level. The second is `ReportSectionRegistry`, unchanged in
 * shape: a component's `view` says which main section the builder groups it under.
 *
 * ## Stored sparse, and absent means «every view»
 *
 * A report or link saved before this existed has no `views` key and keeps every tab it had. A list
 * is stored only when somebody chose one, and it is never empty — a report with no main section is
 * not a report, so an empty or malformed list reads as absent.
 *
 * ## A view off takes its data with it
 *
 * Hiding a tab is not a CSS rule. A component that NO enabled view draws is resolved as hidden by the
 * operator (`ReportSectionSurfaces::settingsFor`), so its payload keys leave the live JSON, the print
 * route and the files exactly as an unticked component's do. `DRAWS` is that map — deliberately
 * generous: a view lists every component it may draw, so narrowing can never strip a block an
 * enabled view still shows.
 */
final class ReportViews
{
    /** In the order the link's tabs are drawn. */
    public const VIEWS = ['summary', 'dashboard', 'platforms', 'content', 'tables'];

    /**
     * The components each view may draw (registry keys). `kpis` is in every view: the header figures
     * and the period strip read it whatever the reader opened.
     *
     * @var array<string, list<string>>
     */
    public const DRAWS = [
        'summary' => ['kpis', 'trends', 'platform_comparison', 'objective_breakdown', 'content_performance', 'funnel', 'budget_pacing', 'site_measurement', 'recommendations'],
        'dashboard' => ['kpis', 'trends', 'platform_comparison', 'budget_pacing', 'funnel', 'content_performance', 'site_measurement', 'store_sales', 'store_products', 'store_orders', 'store_audience', 'recommendations', 'detailed_tables', 'objective_breakdown', 'advanced_segmentation'],
        'platforms' => ['kpis', 'trends', 'platform_comparison', 'content_performance', 'account_breakdown'],
        'content' => ['kpis', 'content_performance'],
        'tables' => ['kpis', 'platform_comparison', 'account_breakdown', 'daily_records', 'content_performance', 'detailed_tables'],
    ];

    /** @var array<string, array{ar: string, en: string}> */
    public const LABELS = [
        'summary' => ['ar' => 'الملخص', 'en' => 'Summary'],
        'dashboard' => ['ar' => 'لوحة الأداء', 'en' => 'Performance dashboard'],
        'platforms' => ['ar' => 'المنصات', 'en' => 'Platforms'],
        'content' => ['ar' => 'المحتوى', 'en' => 'Content'],
        'tables' => ['ar' => 'الجداول', 'en' => 'Tables'],
    ];

    /**
     * A stored or submitted list, cleaned: known views only, in canonical order, or null for «every
     * view» (absent, malformed or empty).
     *
     * @return list<string>|null
     */
    public static function normalise(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $wanted = array_filter($raw, 'is_string');
        $views = array_values(array_filter(self::VIEWS, static fn (string $v): bool => in_array($v, $wanted, true)));

        return $views === [] ? null : $views;
    }

    /**
     * A conjunction of two layers — the report's and the link's. A link narrows a report and never
     * widens it; null on either side means that side did not narrow.
     *
     * @param  list<string>|null  $report
     * @param  list<string>|null  $link
     * @return list<string>|null
     */
    public static function narrow(?array $report, ?array $link): ?array
    {
        if ($report === null) {
            return $link;
        }
        if ($link === null) {
            return $report;
        }
        $both = array_values(array_intersect($report, $link));

        // Disjoint layers cannot leave a report with nothing: the report's own choice stands.
        return $both === [] ? $report : $both;
    }

    /**
     * The components no enabled view draws — what a narrowed view set hides.
     *
     * @param  list<string>|null  $views
     * @param  list<string>  $components  every registry key
     * @return list<string>
     */
    public static function undrawn(?array $views, array $components): array
    {
        if ($views === null) {
            return [];
        }
        $drawn = [];
        foreach ($views as $view) {
            foreach (self::DRAWS[$view] ?? [] as $key) {
                $drawn[$key] = true;
            }
        }

        return array_values(array_filter($components, static fn (string $k): bool => ! isset($drawn[$k])));
    }

    /** @return list<array{key: string, title_ar: string, title_en: string}> */
    public static function catalogue(): array
    {
        return array_map(static fn (string $v): array => ['key' => $v, 'title_ar' => self::LABELS[$v]['ar'], 'title_en' => self::LABELS[$v]['en']], self::VIEWS);
    }
}
