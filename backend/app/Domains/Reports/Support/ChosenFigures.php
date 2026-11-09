<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Metrics\Services\ContentFormatComparison;
use App\Domains\Reports\Analytics\ObjectiveReportAnalytics;
use App\Domains\Reports\Measurement\Ga4ReportSections;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportAutoComposer;
use App\Domains\Reports\Services\ReportStructure;
use App\Domains\Reports\Services\ReportTemplateEngine;

/**
 * REPORT-FILTERS-FUNCTIONAL-001 — a KPI or a column the operator did not choose leaves the answer, not only the list.
 *
 * Owner decision (2026-11-05): «ظلت مقاييس/أعمدة غير مختارة في payload/الطباعة؛ يجب إتمام قطعها من الخادم». The server cut the lists
 * (`metric_set`, `kpi_allowlist`, `report_columns`) and the CSV and XLSX, and still sent every value to the page and the print document,
 * because the same rows feed other cards and charts. A value the page does not draw is one network tab away from the reader.
 *
 * One function at the serving boundary, beside {@see HiddenMoney} and {@see PreviousComparison}: nothing here writes `reports.data`. A
 * figure no chosen key names leaves wherever it sits, and a card or chart that truly needs it loses it with it: a row named by an
 * unchosen metric leaves its list, a block named by one takes the silent shape its own reader already draws as nothing. Never a blank
 * and never a zero that reads as a real figure.
 *
 * Only a stated choice cuts: the link's list, a picked automatic composition, the report's scope, a customised report set. An objective's
 * default template is nobody's choice, so a report with no list keeps every value and its answer is byte for byte what it was. An
 * internal report and an operator's view never come through here.
 */
final class ChosenFigures
{
    /** chosen KPI => the keys it answers for. A key not listed answers for itself. */
    private const ANSWERS_FOR = [
        'spend' => self::SPEND,
        'revenue' => self::REVENUE,
        'conversions' => ['conversions', 'orders', 'outcome'],
        'purchases' => ['purchases', 'orders', 'outcome'],
        'orders' => ['orders', 'conversions', 'outcome'],
        'results' => ['outcome'],
        'leads' => ['leads', 'outcome'],
        'cpa' => ['cpa', 'cost_per_conversion'],
        'cost_per_result' => ['cost_per_conversion'],
    ];

    /** The amount spent and everything that states it. The per-unit costs are KPIs of their own, chosen or not. */
    private const SPEND = [
        'spend', 'spend_original', 'spend_withheld_rows', 'spend_withheld', 'spend_share', 'spend_coverage', 'spend_not_reported', 'funnel_spend', 'cost_per',
        'spent', 'remaining', 'consumed_pct', 'pace', 'projected_spend', 'daily_average', 'over_under', 'excluded_spend', 'includes_non_sales_spend',
        'spend_by_kind', 'spend_mix',
    ];

    /** The amount earned and everything that states it. */
    private const REVENUE = [
        'revenue', 'revenue_original', 'revenue_withheld_rows', 'revenue_coverage', 'gross_revenue', 'attributed_revenue',
        'platform_reported_revenue', 'store_confirmed_revenue', 'total_revenue',
    ];

    /** Every KPI an answer may carry that is not spend or revenue. A figure under one of these is a KPI, whichever block it sits in. */
    private const KPIS = [
        'impressions', 'clicks', 'conversions', 'reach', 'video_views', 'video_completions', 'landing_page_views', 'leads', 'qualified_leads', 'form_leads',
        'purchases', 'add_to_cart', 'checkout', 'installs', 'registrations', 'in_app_events', 'engagements', 'roas', 'blended_roas', 'attributed_roas',
        'cpa', 'blended_cpa', 'ctr', 'cpc', 'cpm', 'frequency', 'cpl', 'cpi', 'cpe', 'cac', 'aov', 'conversion_rate', 'engagement_rate', 'video_completion_rate',
        'orders', 'sign_ups', 'app_opens', 'page_views', 'video_views_2s', 'video_views_3s', 'video_views_6s', 'video_p25', 'video_p50', 'video_p75', 'video_p100',
        'video_avg_watch_seconds', 'cost_per_view', 'cost_per_lpv', 'cost_per_result', 'cost_per_conversion', 'view_rate', 'completion_rate', 'hook_rate', 'decay', 'outcome',
        'results', 'platform_value',
    ];

    /** A table's choosable column => the keys it answers for ({@see ReportColumns::TABLES}). */
    private const COLUMN_ANSWERS = [
        'spend' => self::SPEND,
        'results' => ['conversions', 'orders', 'purchases', 'leads', 'form_leads', 'qualified_leads', 'installs', 'registrations', 'in_app_events', 'sign_ups', 'outcome', 'results'],
        'cost' => ['cpa', 'blended_cpa', 'cpl', 'cpi', 'cost_per_result', 'cost_per_conversion'],
        'impressions' => ['impressions'],
        'clicks' => ['clicks'],
        'ctr' => ['ctr'],
    ];

    /** Blocks that hold no KPI figure of their own and are not this function's to rewrite. `links` has no switch and is listed in the audit. */
    private const NOT_FIGURES = [
        'preview', 'disclaimer', 'freshness', 'freshness_summary', 'report_sections', 'report_views', 'sections', 'available', 'applied', 'settings',
        'links', 'outline', 'slides', 'columns', 'report_columns', 'breakdowns', 'reported', 'reported_by_platform', 'kpi_labels',
        'store_funnel', 'site_measurement', 'objective_analytics', 'results', 'verified_result',
        // GA4-REPORT-SECTIONS-001 — GA4's own vocabulary (sessions, purchases); only its money follows the KPI list, below.
        'ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables', 'ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons',
        // CONTENT-FORMAT-COMPARISON-001 — its own block, cut for its audience when built; only its money follows the KPI list, below.
        'content_format_comparison',
    ];

    /** Which row lists each table's columns sit on. A stated table reaches these and no other block. */
    private const TABLE_ROWS = [
        'platforms' => ['platforms', 'platform_drilldowns', 'attention'],
        'accounts' => ['accounts'],
        'content' => ['ads', 'ads_roster', 'ads_weakest', 'ads_groups', 'ads_platform_groups', 'top_creatives', 'worst_creatives', 'creatives', 'content_formats'],
        'days' => ['timeseries', 'daily_rows', 'platform_series'],
    ];

    /**
     * @param  list<string>  $metrics  the chosen KPIs, in their own order; empty means none was chosen
     * @param  array<string, list<string>>  $columns  only the tables somebody restricted
     */
    private function __construct(private readonly array $metrics, private readonly array $columns) {}

    /**
     * What a served document of this report, through this link, may carry: the link's own list, then a picked automatic composition, then
     * the report's scope and its customised set. A link's own columns win over the report's, as `report_columns` already reads them.
     */
    public static function for(Report $report, ?ReportShare $share): self
    {
        if (($report->audience ?? 'client') === 'internal') {
            return new self([], []);
        }

        $columns = $share !== null ? ReportColumns::forShare($share)->toArray() : [];

        return new self(self::listedMetrics($report, $share), $columns !== [] ? $columns : ReportColumns::forReport($report)->toArray());
    }

    /** The live payload and its drawers state the link's own lists (`metrics`, `columns`) and no other, so those are what they are cut to. */
    public static function forLive(ReportShare $share): self
    {
        return new self(self::strings(($share->scope ?? [])['metrics'] ?? []), ReportColumns::forShare($share)->toArray());
    }

    /** @return list<string> */
    private static function listedMetrics(Report $report, ?ReportShare $share): array
    {
        $own = $share !== null ? self::strings(($share->scope ?? [])['metrics'] ?? []) : [];
        if ($own !== []) {
            return $own;
        }
        $auto = ReportAutoComposer::chosenMetrics($report, $share);
        if ($auto !== null) {
            return $auto;
        }
        $scoped = self::strings(((array) ($report->scope ?? []))['metrics'] ?? []);
        if ($scoped !== []) {
            return $scoped;
        }
        $custom = self::strings(((array) ($report->config ?? []))['metric_set'] ?? []);

        return $custom !== [] && ! app(ReportTemplateEngine::class)->isPreset($custom) ? $custom : [];
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter((array) $values, static fn ($v): bool => is_string($v) && $v !== ''));
    }

    public function isActive(): bool
    {
        return $this->metrics !== [] || $this->columns !== [];
    }

    /** Whether an order by this metric would rank by a figure this answer does not carry. */
    public function hidesMetric(string $metric): bool
    {
        return isset($this->unchosen()[$metric]);
    }

    /**
     * The page's or the print document's body, cut. No list: the very same array.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cut(array $payload): array
    {
        if (! $this->isActive()) {
            return $payload;
        }

        $cut = $this->unchosen();
        if ($cut !== []) {
            $payload = $this->withoutProse($payload, $cut);
            $payload = $this->withoutDependentBlocks($payload, $cut);
            $payload = $this->walk($payload, $cut);
            if (array_key_exists('metric_set', $payload)) {
                $payload['metric_set'] = $this->metrics;
            }
            $payload = $this->withListedColumns($payload, $cut);
        }

        return $this->withColumns($payload);
    }

    /**
     * A platform's drawer: the row of the platforms table, its days and its content.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cutDrawer(array $payload): array
    {
        if (! $this->isActive()) {
            return $payload;
        }

        $payload = $this->cut($payload);
        foreach (['totals', 'objectives', 'shares', 'timeseries'] as $block) {
            if (is_array($payload[$block] ?? null) && isset($this->columns['platforms'])) {
                $payload[$block] = $this->walk($payload[$block], $this->unchosenBy('platforms'));
            }
        }

        return $payload;
    }

    /**
     * An answer that is the content section and nothing else: the library, its summary, one creative, a comparison.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cutContent(array $payload): array
    {
        if (! $this->isActive()) {
            return $payload;
        }

        $payload = $this->cutPermissions($payload);
        $payload = $this->walk($payload, $this->unchosen());

        return isset($this->columns['content']) ? $this->walk($payload, $this->unchosenBy('content')) : $payload;
    }

    /**
     * A section with its own address and its own vocabulary (widgets, attribution): only the KPI list reaches it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cutSection(array $payload): array
    {
        return $this->metrics === [] ? $payload : $this->walk($payload, $this->unchosen());
    }

    /**
     * The content permissions the page reads before it draws a column: a column whose figures left is not drawn empty.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function cutPermissions(array $body): array
    {
        $permissions = $body['permissions'] ?? null;
        if (is_array($permissions)) {
            $body['permissions'] = $this->narrowPermissions($permissions);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, mixed>
     */
    public function narrowPermissions(array $permissions): array
    {
        $hidden = $this->unchosen() + (isset($this->columns['content']) ? $this->unchosenBy('content') : []);
        foreach (['spend', 'revenue', 'cpa', 'roas'] as $flag) {
            if (isset($hidden[$flag], $permissions[$flag])) {
                $permissions[$flag] = false;
            }
        }

        return $permissions;
    }

    /** @return array<string, true> every key of every KPI the chosen list does not answer for; empty with no list */
    private function unchosen(): array
    {
        if ($this->metrics === []) {
            return [];
        }

        $kept = [];
        foreach ($this->metrics as $metric) {
            foreach (self::ANSWERS_FOR[$metric] ?? [$metric] as $key) {
                $kept[$key] = true;
            }
            $kept[$metric] = true;
        }
        if (isset($kept['spend'], $kept['revenue'])) {
            $kept['platform_value'] = true;
        }

        $cut = array_diff_key(array_fill_keys([...self::SPEND, ...self::REVENUE, ...self::KPIS], true), $kept);
        // The currency of an amount names nothing once neither amount is left.
        if (! isset($kept['spend']) && ! isset($kept['revenue'])) {
            $cut += array_fill_keys(HiddenMoney::CURRENCY, true);
        }

        return $cut;
    }

    /** @return array<string, true> every KPI key a restricted table's own columns do not answer for */
    private function unchosenBy(string $table): array
    {
        $kept = [];
        foreach ($this->columns[$table] ?? [] as $column) {
            foreach (self::COLUMN_ANSWERS[$column] ?? [] as $key) {
                $kept[$key] = true;
            }
        }

        $cut = array_diff_key(array_fill_keys([...self::SPEND, ...self::REVENUE, ...self::KPIS], true), $kept);
        if (! isset($kept['spend'])) {
            $cut += array_fill_keys(HiddenMoney::CURRENCY, true);
        }

        return $cut;
    }

    /**
     * A table nobody restricted follows the KPI list: its column of an unchosen figure would be a column of dashes. Stated in the same
     * key each surface already reads (`columns` on the live payload, `report_columns` on a document), so every table, the PDF's
     * included, draws only what the answer carries. The operator's own restriction of a table is never widened.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function withListedColumns(array $payload, array $cut): array
    {
        $key = array_key_exists('columns', $payload) ? 'columns' : 'report_columns';
        $stated = is_array($payload[$key] ?? null) ? $payload[$key] : [];

        foreach (ReportColumns::TABLES as $table => $known) {
            if (isset($this->columns[$table]) || isset($stated[$table])) {
                continue;
            }
            $kept = array_values(array_filter($known, static fn (string $column): bool => ! isset(self::COLUMN_ANSWERS[$column])
                || array_diff(self::COLUMN_ANSWERS[$column], array_keys($cut)) !== []));
            if ($kept !== $known) {
                $stated[$table] = $kept;
            }
        }

        if ($stated !== []) {
            $payload[$key] = $stated;
        }

        return $payload;
    }

    /**
     * Sentences state figures, and a sentence is not cut around its figure. Each says what it reveals; one that reveals an unchosen KPI
     * takes its neutral variant or leaves, and one that says nothing leaves — fail closed, as {@see HiddenMoney} treats them.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function withoutProse(array $payload, array $cut): array
    {
        // The generated summary states the period's money and cost in sentences with no statement of which; the same rule as a link hiding money.
        unset($payload['summary']);

        foreach (['findings', 'observations', 'recommendations', 'next_steps'] as $list) {
            if (is_array($payload[$list] ?? null)) {
                // Only an item that STATES an unchosen figure leaves; an operator's own note states none and stays (nothing hidden by default).
                $payload[$list] = array_values(array_filter($payload[$list], static fn ($item): bool => ! is_array($item) || ! self::states($item, $cut)));
            }
        }

        if (is_array($payload['platform_notes'] ?? null)) {
            foreach ($payload['platform_notes'] as $provider => $note) {
                if (is_array($note) && self::states($note, $cut)) {
                    $neutral = (array) ($note['neutral'] ?? []);
                    $note['strengths'] = array_values((array) ($neutral['strengths'] ?? []));
                    $note['weaknesses'] = array_values((array) ($neutral['weaknesses'] ?? []));
                    $payload['platform_notes'][$provider] = $note;
                }
            }
        }

        // The reading of the two ends compares figures in words; it has no statement of which, so it is silent here.
        if (is_array($payload['ads_reading'] ?? null) && ($payload['ads_reading']['signal'] ?? null) !== null) {
            $payload['ads_reading'] = ['signal' => null, 'context' => null, 'explanation' => null, 'action' => null, 'evidence' => [], 'silent_reason' => 'not_chosen'];
        }

        return $payload;
    }

    /**
     * Blocks that cannot stand without an unchosen figure: each takes the empty shape its own reader expects.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function withoutDependentBlocks(array $payload, array $cut): array
    {
        // A pacing row is spend against a plan.
        if (isset($cut['spend']) && array_key_exists('budget', $payload)) {
            $payload['budget'] = is_array($payload['budget']) ? [] : null;
        }

        // «Best on this figure» ranks by the figure.
        if (is_array($payload['best'] ?? null)) {
            foreach (['platform_by_cpa' => 'cpa', 'platform_by_roas' => 'roas', 'platform_by_results' => 'conversions'] as $key => $metric) {
                if (isset($cut[$metric])) {
                    $payload['best'][$key] = null;
                }
            }
            if (isset($cut[(string) ($payload['best']['basis']['key'] ?? '')])) {
                $payload['best']['basis'] = null;
                $payload['best']['platform'] = null;
                $payload['best']['platform_value'] = null;
            }
        }

        // A stage's rate from the stage before gives the unchosen stage back.
        if (is_array($payload['funnel'] ?? null)) {
            $payload['funnel'] = array_map(static function ($stage) use ($cut) {
                if (is_array($stage) && isset($cut[(string) ($stage['from_stage'] ?? '')])) {
                    $stage['from_stage'] = null;
                    $stage['step_rate'] = null;
                    $stage['drop_off'] = null;
                }

                return $stage;
            }, $payload['funnel']);
        }

        if (is_array($payload['attention'] ?? null)) {
            $payload['attention'] = $this->attentionWithout($payload['attention'], $cut);
            $payload = ReportStructure::refresh($payload, 'recommendations');
        }

        if (is_array($payload['results'] ?? null)) {
            $payload['results'] = $this->resultsWithout($payload['results'], $cut);
        }

        if (is_array($payload['objective_analytics'] ?? null)) {
            $payload['objective_analytics'] = ObjectiveReportAnalytics::redact($payload['objective_analytics'], array_keys($cut));
        }

        // The store and the site keep their own vocabulary (orders, sessions); only the money the list left out leaves them.
        $hideSpend = isset($cut['spend']);
        $hideRevenue = isset($cut['revenue']);
        foreach (['store_funnel', 'site_measurement'] as $block) {
            if (is_array($payload[$block] ?? null)) {
                $payload[$block] = HiddenMoney::redact($payload[$block], $hideSpend, $hideRevenue);
            }
        }
        if ($hideRevenue) {
            $payload = Ga4ReportSections::withoutMoney($payload);
        }
        if (is_array($payload[ContentFormatComparison::KEY] ?? null)) {
            $payload[ContentFormatComparison::KEY] = ContentFormatComparison::withoutMetrics($payload[ContentFormatComparison::KEY], array_keys($cut));
        }
        if (($hideSpend || $hideRevenue) && is_array($payload[ContentFormatComparison::KEY] ?? null)) {
            $payload[ContentFormatComparison::KEY] = ContentFormatComparison::forAudience($payload[ContentFormatComparison::KEY], false, $hideSpend, $hideRevenue);
        }
        if (is_array($payload['store_funnel']['stages'] ?? null)) {
            // A stage of an unchosen amount leaves the funnel: «not measured» would be untrue of a figure the store did measure.
            $payload['store_funnel']['stages'] = array_values(array_filter(
                $payload['store_funnel']['stages'],
                static fn ($stage): bool => ! (is_array($stage) && (($hideRevenue && ($stage['key'] ?? null) === 'revenue') || ($hideSpend && ($stage['key'] ?? null) === 'spend'))),
            ));
        }
        if ($hideRevenue && is_array($payload['store_funnel'] ?? null)) {
            $payload['store_funnel']['comparisons']['products'] = [];
        }
        if ($hideSpend && is_array($payload['store_funnel'] ?? null)) {
            $payload['store_funnel']['comparisons']['platforms'] = [];
        }

        return $payload;
    }

    /**
     * @param  list<mixed>  $items
     * @param  array<string, true>  $cut
     * @return list<array<string, mixed>>
     */
    private function attentionWithout(array $items, array $cut): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $kpis = [];
            $primaryLeft = false;
            foreach ((array) ($item['kpis'] ?? []) as $kpi) {
                if (isset($cut[(string) ($kpi['key'] ?? '')])) {
                    $primaryLeft = $primaryLeft || ($kpi['primary'] ?? false) === true;

                    continue;
                }
                $kpis[] = $kpi;
            }
            // The finding is about its primary figure: without it there is no evidence left to read.
            if ($primaryLeft) {
                continue;
            }
            $item['kpis'] = $kpis;
            if (isset($cut['spend'])) {
                $item['impact'] = null;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Results are not a card of the KPI list: the link's result events define them (`result_events`, `results_off`), and that definition is
     * its own stated control. The list reaches only what divides back to an unchosen amount: a cost per result is spend over the count beside it.
     *
     * @param  array<string, mixed>  $results
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function resultsWithout(array $results, array $cut): array
    {
        if (! isset($cut['spend'])) {
            return $results;
        }

        $results['cost'] = null;
        if (is_array($results['leads'] ?? null)) {
            $results['leads']['cost'] = null;
        }
        foreach ((array) ($results['by_platform'] ?? []) as $provider => $row) {
            if (is_array($row)) {
                unset($row['cost'], $row['spend']);
                $results['by_platform'][$provider] = $row;
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withColumns(array $payload): array
    {
        foreach (self::TABLE_ROWS as $table => $blocks) {
            if (! isset($this->columns[$table])) {
                continue;
            }
            $cut = $this->unchosenBy($table);
            foreach ($blocks as $block) {
                if (is_array($payload[$block] ?? null)) {
                    $payload[$block] = $block === 'attention' ? $this->attentionWithout($payload[$block], $cut) : $this->walk($payload[$block], $cut);
                }
            }
            if ($table === 'platforms') {
                $payload = $this->platformBlocksWithout($payload, $cut);
            }
            if ($table === 'days') {
                $payload = $this->dailyBlocksWithout($payload, $cut);
            }
        }

        return $payload;
    }

    /**
     * What states one platform's figure outside the platforms list: its note, its best line, its Results and its share of a family.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function platformBlocksWithout(array $payload, array $cut): array
    {
        $payload = $this->withoutPlatformProse($payload, $cut);

        // The platform's defined Result stays with its definition; its cost leaves with the spend column it divides back to.
        if (isset($cut['spend']) && is_array($payload['results']['by_platform'] ?? null)) {
            foreach ($payload['results']['by_platform'] as $provider => $row) {
                if (is_array($row)) {
                    unset($row['cost'], $row['spend']);
                    $payload['results']['by_platform'][$provider] = $row;
                }
            }
        }

        foreach ((array) ($payload['objective_analytics']['families'] ?? []) as $i => $family) {
            foreach ((array) ($family['platforms'] ?? []) as $p => $row) {
                if (is_array($row)) {
                    $family['platforms'][$p] = $this->walk($row, $cut);
                }
            }
            foreach (['platform_ranking'] as $ranking) {
                if (isset($cut[(string) ($family[$ranking]['metric'] ?? '')])) {
                    $family[$ranking] = ['metric' => null, 'best' => null, 'weakest' => null, 'reason' => 'not_chosen', 'eligible' => 0, 'candidates' => 0];
                }
            }
            if (isset($cut['outcome'])) {
                $family['contribution'] = null;
            }
            $payload['objective_analytics']['families'][$i] = $family;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function withoutPlatformProse(array $payload, array $cut): array
    {
        if (is_array($payload['platform_notes'] ?? null)) {
            foreach ($payload['platform_notes'] as $provider => $note) {
                if (is_array($note) && self::states($note, $cut)) {
                    $neutral = (array) ($note['neutral'] ?? []);
                    $note['strengths'] = array_values((array) ($neutral['strengths'] ?? []));
                    $note['weaknesses'] = array_values((array) ($neutral['weaknesses'] ?? []));
                    $payload['platform_notes'][$provider] = $note;
                }
            }
        }
        // A finding about one platform's figure and a sentence naming the platform's value are the platforms table, in words.
        foreach (['findings', 'observations'] as $list) {
            if (is_array($payload[$list] ?? null)) {
                $payload[$list] = array_values(array_filter($payload[$list], static fn ($item): bool => ! (is_array($item)
                    && (($item['platform'] ?? null) !== null || ($item['scope']['type'] ?? null) === 'platform')
                    && self::states($item, $cut))));
            }
        }
        if (is_array($payload['best'] ?? null) && isset($cut[(string) ($payload['best']['basis']['key'] ?? '')])) {
            $payload['best']['platform_value'] = null;
        }

        return $payload;
    }

    /**
     * A family's daily trend is the days table of that family.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, true>  $cut
     * @return array<string, mixed>
     */
    private function dailyBlocksWithout(array $payload, array $cut): array
    {
        foreach ((array) ($payload['objective_analytics']['families'] ?? []) as $i => $family) {
            if (is_array($family['trend'] ?? null) && isset($cut[(string) ($family['trend']['metric'] ?? '')])) {
                $family['trend'] = ['metric' => null, 'points' => []] + $family['trend'];
                $family['trend']['metric'] = null;
                $family['trend']['points'] = [];
            } elseif (is_array($family['trend'] ?? null)) {
                $family['trend'] = $this->walk($family['trend'], $cut);
            }
            $payload['objective_analytics']['families'][$i] = $family;
        }

        return $payload;
    }

    /**
     * The one walk: a key that IS an unchosen figure leaves at any depth, a list's row named by one leaves its list, and a block named by
     * one takes the silent shape. A boolean under such a key states no figure (`reported`, a permission) and stays.
     *
     * @param  array<array-key, mixed>  $node
     * @param  array<string, true>  $cut
     * @return array<array-key, mixed>
     */
    private function walk(array $node, array $cut): array
    {
        $isList = array_is_list($node);

        foreach ($node as $key => $value) {
            if (is_string($key) && is_array($value) && in_array($key, self::NOT_FIGURES, true)) {
                continue;
            }
            if (is_string($key) && isset($cut[$key]) && ! is_bool($value)) {
                unset($node[$key]);

                continue;
            }
            // A ranked row's reason states the figure it was ranked on («CPA 66»).
            if ($key === 'reason' && is_string($value) && array_key_exists('reason_basis', $node)
                && (! is_string($node['reason_basis']) || isset($cut[$node['reason_basis']]))) {
                $node[$key] = null;

                continue;
            }
            if ($key === 'headline_metrics' && is_array($value)) {
                $node[$key] = array_values(array_filter($value, static fn ($m): bool => ! (is_string($m) && isset($cut[$m]))));

                continue;
            }
            if (! is_array($value)) {
                continue;
            }

            $named = self::namedBy($value, $cut);
            if ($named && self::isFigure($value)) {
                // A row or a block that IS one figure of the unchosen KPI: it leaves its list, or is no block.
                if ($isList) {
                    unset($node[$key]);
                } else {
                    $node[$key] = null;
                }

                continue;
            }
            if ($named && ! $isList) {
                $node[$key] = self::silent($value);

                continue;
            }
            // A list row only NAMED by the metric (a gallery group ranked on it) keeps its own content, cut like any other.

            $node[$key] = $this->walk($value, $cut);
        }

        return $isList ? array_values($node) : $node;
    }

    /**
     * Whether a sentence states an unchosen figure, by its own statement: `reveals`, its `metric`, or the KPI its label names. A sentence
     * that says nothing about a figure states none — the cut happens only on what is stated, unlike hidden money, which fails closed.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, true>  $cut
     */
    private static function states(array $item, array $cut): bool
    {
        if (is_string($item['metric'] ?? null) && isset($cut[$item['metric']])) {
            return true;
        }
        if (is_array($item['reveals'] ?? null)) {
            return array_intersect($item['reveals'], array_keys($cut)) !== [];
        }
        $metric = is_string($item['kpi'] ?? null) ? HiddenMoney::metricForLabel($item['kpi']) : null;

        return $metric !== null && isset($cut[$metric]);
    }

    /** @param  array<string, true>  $cut */
    private static function namedBy(array $row, array $cut): bool
    {
        foreach (['metric', 'stage', 'key'] as $name) {
            if (is_string($row[$name] ?? null) && isset($cut[$row[$name]])) {
                return true;
            }
        }

        return false;
    }

    /** A row whose own figure sits under one of these keys is that figure (a stage's count, a KPI's value, a leader's value). */
    private static function isFigure(array $row): bool
    {
        foreach (['value', 'current', 'before', 'count', 'amount'] as $key) {
            if (array_key_exists($key, $row) && ! is_array($row[$key]) && ! is_bool($row[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shape a block already takes when it has nothing to say: `metric` null, lists empty, figures null, its own words kept.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function silent(array $block): array
    {
        foreach ($block as $key => $value) {
            $block[$key] = match (true) {
                is_array($value) => array_is_list($value) ? [] : null,
                is_int($value), is_float($value) => null,
                default => $value,
            };
        }
        $block['metric'] = null;

        return $block;
    }
}
