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
     * The eight sections, in the order a report is read.
     *
     * Fixed order, because the sequence is itself an argument: what happened, then how it performed
     * overall, then where it happened, then what it was bought for, then which campaigns and ads
     * carried it, and only then what to do about it. A findings section read before the evidence is
     * an opinion; read after it, it is a conclusion.
     */
    private const ORDER = [
        'executive_summary',
        'performance',
        'platforms',
        'objectives',
        'campaigns',
        'ads',
        'findings',
        'recommendations',
    ];

    private const TITLES = [
        'executive_summary' => ['ar' => 'الملخّص التنفيذي', 'en' => 'Executive summary'],
        'performance' => ['ar' => 'الأداء العام', 'en' => 'Overall performance'],
        'platforms' => ['ar' => 'تفصيل المنصات', 'en' => 'Platform breakdown'],
        'objectives' => ['ar' => 'التفصيل حسب الهدف', 'en' => 'Breakdown by objective'],
        'campaigns' => ['ar' => 'الحملات', 'en' => 'Campaigns'],
        'ads' => ['ar' => 'الإعلانات والمواد', 'en' => 'Ads and media'],
        'findings' => ['ar' => 'النتائج', 'en' => 'Findings'],
        'recommendations' => ['ar' => 'التوصيات', 'en' => 'Recommendations'],
    ];

    /**
     * Why a section is not here. One of a fixed set — never a free sentence, so a renderer can
     * translate it and a test can assert on it.
     */
    private const REASONS = [
        'no_summary_could_be_composed' => ['ar' => 'لا ملخّص يمكن تكوينه من أرقام هذه الفترة.', 'en' => 'No summary could be composed from this period’s figures.'],
        'no_figures_in_this_window' => ['ar' => 'لا أرقام في هذه الفترة.', 'en' => 'There are no figures in this window.'],
        'no_platform_reported_in_this_window' => ['ar' => 'لم تُبلّغ أي منصة بأرقام في هذه الفترة.', 'en' => 'No platform reported figures in this window.'],
        'no_objective_split_available' => ['ar' => 'لا تقسيم حسب الهدف متاح لهذه الفترة.', 'en' => 'No objective split is available for this window.'],
        'no_campaign_spent_in_this_window' => ['ar' => 'لا حملة أنفقت في هذه الفترة.', 'en' => 'No campaign spent in this window.'],
        'no_creatives_in_window' => ['ar' => 'لا إعلانات ضمن نطاق هذا التقرير وفترته.', 'en' => 'No ads fall inside this report’s scope and window.'],
        'no_rankable_metric_for_this_objective' => ['ar' => 'لا مقياس يصح ترتيب الإعلانات به لهذا الهدف.', 'en' => 'No metric ranks ads honestly for this objective.'],
        'no_ads_to_show' => ['ar' => 'لا إعلانات تُعرض.', 'en' => 'There are no ads to show.'],
        'no_finding_the_figures_support' => ['ar' => 'لا نتيجة تدعمها الأرقام في هذه الفترة.', 'en' => 'No finding is supported by the figures in this period.'],
        'no_recommendation_the_figures_support' => ['ar' => 'لا توصية تدعمها الأرقام في هذه الفترة.', 'en' => 'No recommendation is supported by the figures in this period.'],
    ];

    /**
     * Read the assembled snapshot and say what it contains.
     *
     * @param  array<string,mixed>  $data  the report snapshot, after every figure is in place
     * @return list<array<string,mixed>>
     */
    public function sections(array $data): array
    {
        $has = fn (string $key): bool => $this->rows($data, $key) !== [];

        $present = [
            'executive_summary' => ($data['summary'] ?? $data['executive_summary'] ?? null) !== null,
            // The KPI block is present whenever the window has figures at all; an empty scope is the
            // one state where a report has nothing to say and says so at the top instead of below.
            'performance' => ($data['kpis']['spend'] ?? null) !== null,
            'platforms' => $has('platforms'),
            // `objective_performance` is the block; its `paths` list is what makes it worth showing.
            'objectives' => ($data['objective_performance']['paths'] ?? []) !== [],
            'campaigns' => $has('campaigns'),
            'ads' => $has('ads'),
            /*
             * Findings and recommendations are LAST and are absent when nothing is supported.
             *
             * They are the only sections that make a claim rather than report a figure, and a claim
             * shown before its evidence — or shown as an empty box — is the part of a report a client
             * remembers wrongly.
             */
            'findings' => $has('findings'),
            'recommendations' => $has('recommendations'),
        ];

        $reason = [
            'executive_summary' => 'no_summary_could_be_composed',
            'performance' => 'no_figures_in_this_window',
            'platforms' => 'no_platform_reported_in_this_window',
            'objectives' => 'no_objective_split_available',
            'campaigns' => 'no_campaign_spent_in_this_window',
            // The ads section states its OWN reason — «no creative in the window» and «no metric
            // ranks ads honestly for this objective» are different facts, and only the section that
            // built the list knows which applies.
            'ads' => is_string($data['ads_absent_reason'] ?? null) ? $data['ads_absent_reason'] : 'no_ads_to_show',
            'findings' => 'no_finding_the_figures_support',
            'recommendations' => 'no_recommendation_the_figures_support',
        ];

        /*
         * The figures each section presents, and — where a figure has already appeared — the reason
         * this section shows it again. Spend in the summary is the headline; spend per platform is
         * where it went; spend per campaign is which decision spent it. Three different questions.
         */
        $figures = [
            'executive_summary' => ['spend', 'results', 'cost_per_result'],
            'performance' => ['spend', 'impressions', 'clicks', 'ctr', 'results'],
            'platforms' => ['spend', 'results', 'share'],
            'objectives' => ['spend', 'results', 'cost_per_result'],
            'campaigns' => ['spend', 'results'],
            'ads' => ['impressions', 'clicks', 'ctr'],
            'findings' => [],
            'recommendations' => [],
        ];

        $repeat = [
            'performance' => 'the summary states the headline; this section is the same figures over the whole period, with the components the headline hides',
            'platforms' => 'the same spend, divided by where it went',
            'objectives' => 'the same spend, divided by what it was bought for — and its cost per result is DIRECT rather than the blended one above',
            'campaigns' => 'the same spend, divided by the campaign that decided it',
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

            /*
             * `absent_reason` is always PRESENT as a key and null when the section is — a renderer
             * that has to ask whether the key exists before reading it is a renderer that will one
             * day print «undefined» to a client.
             */
            $section['absent_reason'] = null;

            if ($isPresent) {
                $section['figures'] = $figures[$key];
                if (isset($repeat[$key])) {
                    $section['repeat_reason'] = $repeat[$key];
                }
            } else {
                $code = $reason[$key];
                $section['absent_reason'] = $code;
                $section['absent_reason_ar'] = self::REASONS[$code]['ar'] ?? $code;
                $section['absent_reason_en'] = self::REASONS[$code]['en'] ?? $code;
            }

            $out[] = $section;
        }

        return $out;
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
}
