<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportTemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * CLIENT-FACING-PRESENTATION-001 — the client's deck ends where the client's work begins.
 *
 * ## The owner's decision, and the contradiction it settles
 *
 * Three client-facing documents, three composers, and two of them already agreed.
 * `ReportStructure::ORDER` drives the PDF and ends with recommendations, saying why — «a findings
 * section read before the evidence is an opinion; read after it, it is a conclusion». The live link
 * ends with `ClientAttention` — «the composition ends where the reader's work begins».
 *
 * The DECK put recommendations SECOND, on a rationale about an OPERATOR: «recommendations are what
 * an operator does next». These are client documents, and the owner has decided:
 *
 *     Executive Summary → KPIs → Trends → Platform / Objective / Creative Performance
 *     → Budget / Funnel → Recommendations → Next Steps
 *
 * with the narrative DATA → VISUAL → COMPARISON → INSIGHT → RECOMMENDATION → ACTION.
 *
 * ## Why this test exists at all
 *
 * Nothing asserted the deck's slide order. A composition can be reordered by accident, or by
 * somebody solving a different problem, and no suite would notice — which is exactly why the order
 * was left alone until it was decided rather than changed quietly.
 *
 * The cases below pin the RELATIONSHIPS the decision is about, not a literal list: a literal list
 * breaks every time a slide is legitimately added, and a reader who has to update it learns to
 * update it without reading it.
 */
final class DeckCompositionOrderTest extends TestCase
{
    /** @return array<string, int> slide id => its position in the deck */
    private function positions(string $objective = 'sales', string $form = 'detailed'): array
    {
        $slides = (new ReportTemplateEngine)->defaultConfig($objective, ['meta', 'google'], $form)['slides'];

        $at = [];

        foreach (array_values($slides) as $index => $slide) {
            $id = (string) $slide['id'];

            /*
             * FIRST occurrence, and duplicates are a failure rather than a silent overwrite.
             *
             * The first version of this helper wrote `$at[$id] = $index` unconditionally, so a deck
             * carrying `recommendations` twice — once at the top and once at the bottom — reported
             * only the LAST position and the injected defect passed. A map keyed by id cannot see a
             * repeat unless it is asked to.
             */
            $this->assertArrayNotHasKey($id, $at, "the deck composed «{$id}» twice");

            $at[$id] = $index;
        }

        return $at;
    }

    /** The decision itself: recommendations are not second, and the evidence comes first. */
    public function test_recommendations_come_after_the_evidence_not_second(): void
    {
        $at = $this->positions();

        $this->assertArrayHasKey('recommendations', $at, 'a detailed deck carries recommendations');

        $this->assertGreaterThan(1, $at['recommendations'], 'recommendations are second again');

        foreach (['executive_summary', 'objective_performance', 'ads', 'budget'] as $evidence) {
            $this->assertArrayHasKey($evidence, $at, "the deck lost its «{$evidence}» slide");
            $this->assertLessThan(
                $at['recommendations'],
                $at[$evidence],
                "«{$evidence}» is evidence and must precede the recommendations drawn from it",
            );
        }
    }

    /**
     * «Keep Next Steps last» — the action, after the recommendation that motivates it.
     *
     * Last of the NARRATIVE, which is what the instruction is about. `data_quality` follows it and
     * is not part of the story: it is the operator's appendix saying how much weight the rest can
     * carry, and CLIENT-DIAGNOSTIC-SEPARATION-001 already withholds it from a client audience. A
     * test demanding the literal last index would be asserting that the appendix does not exist.
     */
    public function test_next_steps_closes_the_narrative(): void
    {
        $at = $this->positions();

        $this->assertArrayHasKey('next_steps', $at);
        $this->assertGreaterThan($at['recommendations'], $at['next_steps'], 'the action must follow the recommendation');

        $after = array_keys(array_filter($at, fn (int $i): bool => $i > $at['next_steps']));

        $this->assertSame(
            ['data_quality'],
            $after,
            'something other than the operator’s appendix was composed after the action: '.implode(', ', $after),
        );
    }

    /**
     * The owner's sequence, as relationships.
     *
     * Executive summary opens the content; trends are read before the breakdowns they summarise;
     * the breakdowns precede budget and funnel; and the whole evidence stack precedes the
     * interpretation.
     */
    public function test_the_sequence_reads_in_the_order_the_owner_named(): void
    {
        $at = $this->positions();

        $this->assertLessThan($at['comparison'], $at['executive_summary'], 'the summary must open the deck');
        $this->assertLessThan($at['objective_performance'], $at['comparison'], 'trends are read before the breakdowns');
        $this->assertLessThan($at['budget'], $at['objective_performance'], 'the breakdowns precede budget');
        $this->assertLessThan($at['recommendations'], $at['observations'], 'insight precedes the recommendation it supports');
    }

    /** The cover is still the cover: a title page, not a section competing with the summary. */
    public function test_the_cover_still_opens_the_document(): void
    {
        $at = $this->positions();

        $this->assertSame(0, $at['cover']);
    }

    /**
     * A five-page summary drops recommendations and next steps, and that is unchanged.
     *
     * «A summary states what happened» — the decision reorders the detailed deck; it does not put
     * two of the most text-heavy sections back into the form built to omit them.
     */
    public function test_a_summary_still_omits_the_action_sections(): void
    {
        $at = $this->positions(form: 'executive_summary');

        $this->assertArrayNotHasKey('recommendations', $at);
        $this->assertArrayNotHasKey('next_steps', $at);
        $this->assertArrayHasKey('executive_summary', $at);
    }
}
