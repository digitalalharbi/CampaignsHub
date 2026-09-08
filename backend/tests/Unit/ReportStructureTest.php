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

    public function test_it_lists_the_seven_sections_in_the_order_a_report_is_read(): void
    {
        $sections = (new ReportStructure)->sections($this->snapshot());

        $this->assertSame(
            ['executive_summary', 'performance', 'platforms', 'objectives', 'ads', 'findings', 'recommendations'],
            array_column($sections, 'key'),
        );
    }

    /**
     * CLIENT-REPORT-ENTITY-BOUNDARY-001 — «الحملات» is not a section of a client report.
     *
     * The outline is what the printed document numbers its headings from, so a `campaigns` entry
     * surviving here would put «3. Campaigns» over a gap in a client's PDF — the section that no
     * renderer draws any more. The snapshot below still CARRIES a roster, because every report
     * generated before this requirement does.
     */
    public function test_no_campaign_section_is_offered_even_when_the_snapshot_carries_a_roster(): void
    {
        $sections = (new ReportStructure)->sections($this->snapshot());

        $this->assertArrayNotHasKey('campaigns', $this->keyed($sections));
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

    /**
     * REPORT-DETAIL-PARITY-001 — the contents may not DENY a section the report plainly has.
     *
     * Found on the owner's live client link. Its outline read:
     *
     *     key: performance   present: false
     *     «لا أرقام في هذه الفترة.» / «There are no figures in this window.»
     *
     * on a payload carrying `totals.spend = 9,842.78`, with the KPI block rendered on the page above
     * it. The rule this row states is that a report's contents must never promise a section the link
     * does not have; this is that rule inverted, and it is the worse direction — a client is told
     * their period is empty over nine thousand of their own money.
     *
     * The cause is a name. A SNAPSHOT calls that block `kpis`; the LIVE payload calls it `totals`,
     * and `sections()` only knew the first. Both are the same figures and both mean the section is
     * there, so both are read.
     */
    public function test_the_live_payload_names_its_figures_totals_and_still_has_a_performance_section(): void
    {
        $sections = collect((new ReportStructure)->sections([
            'totals' => ['spend' => 9842.78, 'conversions' => 566],
            'platforms' => [['provider' => 'snapchat', 'spend' => 9842.78]],
        ]))->keyBy('key');

        $this->assertTrue(
            $sections['performance']['present'],
            'the contents denied a performance section on a payload carrying 9,842.78 in spend',
        );
        $this->assertNull($sections['performance']['absent_reason']);
    }

    /** The snapshot spelling keeps working — it is the same section under the other name. */
    public function test_the_snapshot_payload_still_has_its_performance_section(): void
    {
        $sections = collect((new ReportStructure)->sections([
            'kpis' => ['spend' => 1200.0],
        ]))->keyBy('key');

        $this->assertTrue($sections['performance']['present']);
    }

    /**
     * And a window that genuinely has no figures still says so. Without this the fix would be a
     * filter that declares every report complete — the opposite failure, and the one a client
     * cannot detect.
     */
    public function test_a_window_with_no_figures_at_all_still_reports_the_section_absent(): void
    {
        $sections = collect((new ReportStructure)->sections([
            'totals' => ['spend' => null],
            'kpis' => ['spend' => null],
        ]))->keyBy('key');

        $this->assertFalse($sections['performance']['present']);
        $this->assertSame('no_figures_in_this_window', $sections['performance']['absent_reason']);
    }

    /**
     * REPORT-DETAIL-PARITY-001 — «nothing was found» and «nothing was looked for» are different facts.
     *
     * On the owner's live client link the contents state, of the client's own data:
     *
     *     «لا نتيجة تدعمها الأرقام في هذه الفترة.»  / "No finding is supported by the figures in this period."
     *     «لا توصية تدعمها الأرقام في هذه الفترة.»  / "No recommendation is supported by the figures…"
     *     «لا ملخّص يمكن تكوينه من أرقام هذه الفترة.» / "No summary could be composed from this period's figures."
     *
     * All three claim the figures were examined and yielded nothing. They were never examined:
     * `LiveReportService` composes none of the three — grep counts 0 against `ReportGenerator`'s 3 —
     * because written analysis is composed when a report is GENERATED, and a live link recomputes
     * its figures on every open instead.
     *
     * A client reading «your figures support no findings» has been told something about their
     * business that nobody checked. That is a heavier claim than the missing section beside it, and
     * it is the same defect as `performance`: a reason that names the wrong cause.
     */
    public function test_a_live_link_does_not_claim_the_figures_supported_no_findings(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections(
            ['totals' => ['spend' => 9842.78]],
            composesNarrative: false,
        ));

        foreach (['findings', 'recommendations', 'executive_summary'] as $key) {
            $this->assertFalse($sections[$key]['present'], "{$key} should still be absent on a live link");
            $this->assertSame(
                'not_composed_for_a_live_link',
                $sections[$key]['absent_reason'],
                "«{$key}» told the client their figures were examined when they never were",
            );
        }
    }

    /**
     * And a GENERATED report keeps the honest version: there, the figures really were examined and
     * really did support nothing, which is a fact about the period worth stating.
     */
    public function test_a_generated_report_still_says_the_figures_supported_nothing(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections(['kpis' => ['spend' => 10.0]]));

        $this->assertSame('no_finding_the_figures_support', $sections['findings']['absent_reason']);
        $this->assertSame('no_recommendation_the_figures_support', $sections['recommendations']['absent_reason']);
        $this->assertSame('no_summary_could_be_composed', $sections['executive_summary']['absent_reason']);
    }

    /** A live link that DOES carry findings still shows them — the flag governs the reason, not the section. */
    public function test_the_flag_never_hides_a_section_that_is_present(): void
    {
        $sections = $this->keyed((new ReportStructure)->sections(
            ['totals' => ['spend' => 1.0], 'findings' => [['title' => 'x']]],
            composesNarrative: false,
        ));

        $this->assertTrue($sections['findings']['present']);
        $this->assertNull($sections['findings']['absent_reason']);
    }
}
