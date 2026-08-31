<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportStructure;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-ANALYTICAL-DEPTH-001 — a report says what it contains, and why anything is missing.
 *
 * The claims here are mostly refusals. A section present because a template listed it, rather than
 * because the evidence supports it, is how a project running one objective ends up with a single row
 * under the heading «Performance by objective» — a comparison of one, which reads exactly like a
 * comparison of four.
 */
final class ReportStructureTest extends TestCase
{
    /** @return array<string,mixed> */
    private function snapshot(array $over = []): array
    {
        return array_merge([
            'summary' => ['Spend rose 12% while cost per order fell'],
            'kpis' => ['spend' => 42_000, 'results' => 380],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 26_000],
                ['provider' => 'tiktok', 'spend' => 16_000],
            ],
            'campaigns' => [['id' => 'c1', 'name' => 'Ramadan', 'spend' => 26_000]],
            'objective_performance' => [
                'paths' => [
                    ['path' => 'awareness', 'spend' => 12_000],
                    ['path' => 'conversion', 'spend' => 30_000],
                ],
            ],
            'findings' => [['title' => 'CPA rose 18%']],
            'recommendations' => [['action' => 'Rebalance toward Meta']],
            'ads' => [['id' => 'a1', 'name' => 'Story 9:16']],
        ], $over);
    }

    private function keyed(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $out[$section['key']] = $section;
        }

        return $out;
    }

    public function test_it_lists_the_eight_sections_in_the_order_a_report_is_read(): void
    {
        $sections = (new ReportStructure)->sections($this->snapshot());

        $this->assertSame(
            ['executive_summary', 'performance', 'platforms', 'objectives', 'campaigns', 'ads', 'findings', 'recommendations'],
            array_column($sections, 'key'),
        );
    }

    /**
     * The section is absent, not present-and-empty.
     *
     * «Findings» over an empty state tells a client the analysis failed. Nothing to report is a
     * different statement from nothing was produced, and only one of them is true here.
     */
    public function test_findings_are_absent_rather_than_empty_when_nothing_is_supported(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections($this->snapshot([
            'findings' => [],
            'recommendations' => [],
        ])));

        $this->assertFalse($sections['findings']['present']);
        $this->assertSame('no_finding_the_figures_support', $sections['findings']['absent_reason']);
        $this->assertArrayNotHasKey('figures', $sections['findings']);
        $this->assertStringContainsString('لا نتيجة تدعمها الأرقام', $sections['findings']['absent_reason_ar']);
    }

    /**
     * No objective split, no objective section.
     *
     * The section exists to compare what the money was bought FOR. With no paths at all there is
     * nothing to compare, and a heading over one row reads as a comparison of four.
     */
    public function test_no_objective_split_gets_no_objective_section(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections($this->snapshot([
            'objective_performance' => ['paths' => []],
        ])));

        $this->assertFalse($sections['objectives']['present']);
        $this->assertSame('no_objective_split_available', $sections['objectives']['absent_reason']);
    }

    public function test_no_platform_reported_gets_no_platform_breakdown(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections($this->snapshot(['platforms' => []])));

        $this->assertFalse($sections['platforms']['present']);
        $this->assertSame('no_platform_reported_in_this_window', $sections['platforms']['absent_reason']);
    }

    /**
     * The ads section states its OWN reason.
     *
     * «No creative in the window» and «no metric ranks ads honestly for this objective» are
     * different facts, and only the code that built the list knows which one applies. A generic
     * «there are no ads» in their place would be true and useless.
     */
    public function test_the_ads_section_carries_the_reason_the_report_gave_it(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections($this->snapshot([
            'ads' => [],
            'ads_absent_reason' => 'no_rankable_metric_for_this_objective',
        ])));

        $this->assertFalse($sections['ads']['present']);
        $this->assertSame('no_rankable_metric_for_this_objective', $sections['ads']['absent_reason']);
    }

    public function test_a_report_with_no_ads_says_so_rather_than_printing_an_empty_gallery(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections($this->snapshot(['ads' => []])));

        $this->assertFalse($sections['ads']['present']);
        $this->assertSame('no_ads_to_show', $sections['ads']['absent_reason']);
    }

    /**
     * Repetition is allowed; unexplained repetition is not.
     *
     * Spend is in the summary, again over the period, again per platform and again per campaign.
     * Each answers a different question and each says which. A figure repeated with no reason
     * teaches a reader that the sections are copies of one another, and they stop reading them.
     */
    public function test_every_repeated_figure_carries_the_reason_it_is_repeated(): void
    {
        $sections = (new ReportStructure)->sections($this->snapshot());

        $seen = [];
        $unexplained = [];

        foreach ($sections as $section) {
            if (($section['present'] ?? false) === false) {
                continue;
            }

            foreach ($section['figures'] as $figure) {
                if (in_array($figure, $seen, true) && ! isset($section['repeat_reason'])) {
                    $unexplained[] = "{$section['key']}:{$figure}";
                }
            }

            $seen = array_merge($seen, $section['figures']);
        }

        $this->assertSame([], $unexplained, 'a figure shown again must say why this section shows it');
    }

    /** An absent section carries no figures — there is nothing there to present. */
    public function test_an_absent_section_presents_nothing(): void
    {
        $sections = (new ReportStructure)->sections($this->snapshot([
            'platforms' => [],
            'campaigns' => [],
            'ads' => [],
            'findings' => [],
            'recommendations' => [],
            'objective_performance' => ['paths' => []],
            'kpis' => [],
        ]));

        foreach ($sections as $section) {
            if ($section['present']) {
                continue;
            }

            $this->assertArrayNotHasKey('figures', $section);
            $this->assertArrayHasKey('absent_reason_en', $section);
        }

        $this->assertSame(
            ['executive_summary'],
            array_values(array_column(array_filter($sections, static fn (array $s): bool => $s['present']), 'key')),
            'with no spend at all only the summary survives',
        );
    }
}
