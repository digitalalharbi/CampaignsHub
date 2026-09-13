<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Services\ClientReportView;
use App\Domains\Reports\Services\ReportTemplateEngine;
use Tests\TestCase;

/**
 * REPORT-SUMMARY-DECISION-001 — what a summary IS, and what an audience changes.
 *
 * Two axes decide a report's shape and they were not agreeing with each other. `form` chose the
 * slide list when the report was generated; `audience` filtered it again at the view, in three
 * places — the print route, the shared link and every export. Between them, one section could be
 * present for an executive and absent for a summary reader, which is a report answering «do I get
 * next steps?» differently depending on which axis you asked.
 *
 * These pin the contract the owner stated: a summary is a DECISION document — it ends on what to do
 * — and an executive report is materially shorter than a client one.
 */
final class ReportCompositionContractTest extends TestCase
{
    private const PLATFORMS = ['meta', 'snapchat', 'tiktok'];

    /** @return list<string> */
    private function types(string $form): array
    {
        $config = app(ReportTemplateEngine::class)->defaultConfig('sales', self::PLATFORMS, $form);

        return array_values(array_map(
            static fn (array $s): string => (string) $s['type'],
            array_filter($config['slides'], static fn (array $s): bool => ($s['visible'] ?? true) === true),
        ));
    }

    public function test_a_summary_ends_on_what_to_do_about_it(): void
    {
        $summary = $this->types('executive_summary');

        // The owner's summary contract names both by name: «concise recommendations», «concise next
        // actions». A decision document that stops at the findings is a diagnosis with no prescription.
        self::assertContains('recommendations', $summary);
        self::assertContains('next_steps', $summary);
    }

    /**
     * The two axes must not disagree about one section. `next_steps` is in the executive set, so a
     * summary without it meant the same reader got an answer that depended on which field was set.
     */
    public function test_neither_axis_can_withhold_a_section_the_other_gives(): void
    {
        $summary = $this->types('executive_summary');

        $executiveOnly = (new \ReflectionClass(ClientReportView::class))->getConstant('EXECUTIVE_SLIDE_TYPES');
        self::assertIsArray($executiveOnly);

        foreach ($executiveOnly as $type) {
            if ($type === 'cover') {
                continue;
            }

            self::assertContains(
                $type,
                $summary,
                "«{$type}» reaches an executive but not a summary — one section, two answers"
            );
        }
    }

    /**
     * A summary is still SHORTER. Restoring its ending must not have quietly made it the full report:
     * the per-platform pages and the operator's appendix are what «detailed» means.
     */
    public function test_a_summary_is_still_a_summary(): void
    {
        $summary = $this->types('executive_summary');
        $detailed = $this->types('detailed');

        self::assertLessThan(count($detailed), count($summary), 'the summary stopped being shorter');
        self::assertNotContains('platform_performance', $summary, 'a per-platform page is depth, not a summary');
        self::assertNotContains('data_quality', $summary, 'the operator appendix belongs to the full report');
        self::assertNotContains('funnel', $summary);

        // And the detailed report keeps everything the summary has.
        foreach ($summary as $type) {
            self::assertContains($type, $detailed, "«{$type}» is in the summary but not the full report");
        }
    }

    /**
     * The audience axis, proven where it actually acts: the view, not the stored config.
     */
    public function test_an_executive_report_is_materially_shorter_than_a_client_one(): void
    {
        $config = app(ReportTemplateEngine::class)->defaultConfig('sales', self::PLATFORMS, 'detailed');
        $payload = ['slides' => $config['slides'], 'kpis' => ['spend' => 1], 'checksum' => 'x'];

        $view = app(ClientReportView::class);
        $client = $view->filter($payload)['slides'] ?? [];
        $executive = $view->executive($payload)['slides'] ?? [];

        self::assertLessThan(
            count($client),
            count($executive),
            'an executive report is the same length as a client one — the audience changes nothing'
        );
        self::assertNotSame([], $executive, 'the executive filter emptied the report');
    }
}
