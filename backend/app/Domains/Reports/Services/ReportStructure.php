<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * REPORT-ANALYTICAL-DEPTH-001 — what this report actually contains, and why anything is missing.
 *
 * ## The defect this exists against
 *
 * A report was a list of slides decided by a template before a single figure was read. Whether the
 * evidence supported a section was never asked: a project running one objective still got an
 * objective breakdown — one row wearing the heading «Performance by objective», which reads as a
 * comparison and is not one. A period that produced no supportable finding still got a «Findings»
 * heading with an empty state under it, which tells a client the analysis failed rather than that
 * there was nothing to report.
 *
 * So the structure is DERIVED from the assembled snapshot, after every figure is in place. A
 * section is present because its evidence is, or it is absent and says which of a fixed set of
 * reasons applies. Nothing here recomputes a figure; it reads what the report already holds, which
 * is what keeps the contents page from disagreeing with the report it describes.
 *
 * ## An absent section is absent
 *
 * Not present-with-an-empty-state. The requirement is explicit about this, and it is the difference
 * between «there was nothing to say here» and «this part is broken». A renderer walks `sections` and
 * draws what it finds; a reader who wants to know why the objective breakdown is not there reads the
 * reason beside it.
 *
 * ## Repetition is allowed, unexplained repetition is not
 *
 * Spend appears in the executive summary, in the overall performance section and again per platform.
 * That is not duplication — each one answers a different question, and the section says which. A
 * figure that appears in a later section without a `repeat_reason` is the failure this records:
 * a number printed twice for no reason teaches a reader that the sections are copies of each other.
 */
final class ReportStructure
{
    /**
     * The seven sections, in the order a report is read.
     *
     * Fixed order, because the sequence is itself an argument: what happened, then how it performed
     * overall, then where it happened, then what it was bought for, then which campaigns and ads
     * carried it, and only then what to do about it. A findings section read before the evidence is
     * an opinion; read after it, it is a conclusion.
     */
    private const ORDER = [
        'executive_summary',
        'overall_performance',
        'platforms',
        'objectives',
        'entities',
        'ads',
        'findings',
    ];

    private const TITLES = [
        'executive_summary' => ['ar' => 'الملخّص التنفيذي', 'en' => 'Executive summary'],
        'overall_performance' => ['ar' => 'الأداء العام', 'en' => 'Overall performance'],
        'platforms' => ['ar' => 'تفصيل المنصات', 'en' => 'Platform breakdown'],
        'objectives' => ['ar' => 'التفصيل حسب الهدف', 'en' => 'Breakdown by objective'],
        'entities' => ['ar' => 'الحملات', 'en' => 'Campaigns'],
        'ads' => ['ar' => 'الإعلانات والمواد', 'en' => 'Ads and media'],
        'findings' => ['ar' => 'النتائج والتوصيات', 'en' => 'Findings and recommendations'],
    ];

    /**
     * Why a section is not here. One of a fixed set — never a free sentence, so a renderer can
     * translate it and a test can assert on it.
     */
    private const REASONS = [
        'no_spend_in_period' => ['ar' => 'لا إنفاق في هذه الفترة.', 'en' => 'Nothing was spent in this period.'],
        'one_platform_only' => ['ar' => 'منصة واحدة فقط — لا تفصيل يُقارَن.', 'en' => 'One platform only — there is no breakdown to compare.'],
        'one_objective_only' => ['ar' => 'هدف واحد فقط — «التفصيل حسب الهدف» صف واحد يحمل عنوان مقارنة.', 'en' => 'One objective only — a breakdown by objective would be a single row under a comparison heading.'],
        'no_campaigns' => ['ar' => 'لا حملات ضمن نطاق هذا التقرير.', 'en' => 'No campaigns fall inside this report’s scope.'],
        'no_ads_in_scope' => ['ar' => 'لم تُرفَق إعلانات بهذا التقرير.', 'en' => 'No ads were included in this report.'],
        'nothing_supported_by_evidence' => ['ar' => 'لا نتيجة تدعمها الأرقام في هذه الفترة.', 'en' => 'No finding is supported by the figures in this period.'],
    ];

    /**
     * Read the assembled snapshot and say what it contains.
     *
     * @param  array<string,mixed>  $data  the report snapshot, after every figure is in place
     * @return list<array<string,mixed>>
     */
    public function sections(array $data): array
    {
        $platforms = $this->rows($data, 'platforms');
        $campaigns = $this->rows($data, 'campaigns');
        $ads = $this->rows($data, 'ads');
        $findings = $this->rows($data, 'findings');
        $recommendations = $this->rows($data, 'recommendations');
        $objectives = $this->objectiveRowsWithSpend($data);
        $spend = (float) ($this->kpi($data, 'spend') ?? 0.0);

        $present = [
            'executive_summary' => true,
            'overall_performance' => true,
            'platforms' => count($platforms) > 1,
            'objectives' => count($objectives) > 1,
            'entities' => $campaigns !== [],
            'ads' => $ads !== [],
            'findings' => $findings !== [] || $recommendations !== [],
        ];

        $reason = [
            'platforms' => $spend <= 0.0 && $platforms === [] ? 'no_spend_in_period' : 'one_platform_only',
            'objectives' => $objectives === [] ? 'no_spend_in_period' : 'one_objective_only',
            'entities' => 'no_campaigns',
            'ads' => 'no_ads_in_scope',
            'findings' => 'nothing_supported_by_evidence',
        ];

        /*
         * The figures each section presents, and — where a figure has already appeared — the reason
         * this section shows it again. Spend in the summary is the headline; spend per platform is
         * where it went; spend per campaign is which decision spent it. Three different questions.
         */
        $figures = [
            'executive_summary' => ['spend', 'results', 'cost_per_result'],
            'overall_performance' => ['spend', 'impressions', 'clicks', 'ctr', 'results'],
            'platforms' => ['spend', 'results', 'share'],
            'objectives' => ['spend', 'results', 'cost_per_result'],
            'entities' => ['spend', 'results'],
            'ads' => ['impressions', 'clicks', 'ctr'],
            'findings' => [],
        ];

        $repeat = [
            'overall_performance' => 'the summary states the headline; this section is the same figures over the whole period, with the components the headline hides',
            'platforms' => 'the same spend, divided by where it went',
            'objectives' => 'the same spend, divided by what it was bought for — and its cost per result is DIRECT rather than the blended one above',
            'entities' => 'the same spend, divided by the campaign that decided it',
            'ads' => 'delivery figures beneath the campaigns, never money — an ad’s share of a campaign’s spend is not a figure any platform reports',
        ];

        $out = [];

        foreach (self::ORDER as $key) {
            $isPresent = $present[$key];

            $section = [
                'key' => $key,
                'title_ar' => self::TITLES[$key]['ar'],
                'title_en' => self::TITLES[$key]['en'],
                'present' => $isPresent,
            ];

            if ($isPresent) {
                $section['figures'] = $figures[$key];
                if (isset($repeat[$key])) {
                    $section['repeat_reason'] = $repeat[$key];
                }
            } else {
                $code = $reason[$key] ?? 'no_spend_in_period';
                $section['absent_reason'] = $code;
                $section['absent_reason_ar'] = self::REASONS[$code]['ar'];
                $section['absent_reason_en'] = self::REASONS[$code]['en'];
            }

            $out[] = $section;
        }

        return $out;
    }

    /**
     * The objective paths that actually SPENT.
     *
     * A path with no spend is not an objective this report can break down: it contributes a row of
     * zeros, and counting it would make «one objective only» read as a comparison of four.
     *
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    private function objectiveRowsWithSpend(array $data): array
    {
        $performance = $data['objective_performance'] ?? null;
        $rows = is_array($performance) ? ($performance['paths'] ?? $performance['objectives'] ?? $performance) : [];

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter(
            array_filter($rows, 'is_array'),
            static fn (array $row): bool => (float) ($row['spend'] ?? 0) > 0,
        ));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<array<string,mixed>>
     */
    private function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /** @param array<string,mixed> $data */
    private function kpi(array $data, string $key): ?float
    {
        $kpis = $data['kpis'] ?? [];

        return is_array($kpis) && isset($kpis[$key]) && is_numeric($kpis[$key]) ? (float) $kpis[$key] : null;
    }
}
