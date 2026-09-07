<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ClientEntityBoundary;
use PHPUnit\Framework\TestCase;

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 / owner ledger row 31 — the verdict travels, the evidence does not.
 *
 * Found on the owner's LIVE client link on 2026-09-07: `totals.spend_coverage.reasons` carried
 * «The last sync failed: No connector is registered for provider 'sandbox'.» — an English exception
 * naming an internal artefact, inside an Arabic report written for a paying client.
 *
 * The block below is that response's own shape, with the real message, so the fix is proved against
 * what Production actually sent rather than against something convenient.
 */
final class ClientEntityBoundaryCoverageTest extends TestCase
{
    /** @return array<string,mixed> */
    private function payload(): array
    {
        return [
            'totals' => [
                'spend' => 9437.86,
                'spend_coverage' => [
                    'state' => 'partial',
                    'expected_contributors' => ['linkedin', 'meta', 'snapchat', 'sandbox'],
                    'included_contributors' => ['linkedin', 'meta', 'snapchat'],
                    'excluded_contributors' => ['sandbox'],
                    'reasons' => ['sandbox' => "The last sync failed: No connector is registered for provider 'sandbox'."],
                ],
            ],
        ];
    }

    public function test_the_operators_evidence_does_not_reach_the_client(): void
    {
        $filtered = ClientEntityBoundary::coverage($this->payload());

        $this->assertSame([], $filtered['totals']['spend_coverage']['reasons']);

        $json = json_encode($filtered, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('No connector is registered', $json);
        $this->assertStringNotContainsString('sandbox\'', $json, 'the exception text survived somewhere else');
    }

    /**
     * The half that matters most. Removing the evidence must not remove the VERDICT: a client whose
     * figures are incomplete has to be told so, and a boundary that deleted the block would publish a
     * partial total under the label it would have used for the whole — the exact failure
     * AGGREGATION-TRUTH-001 exists to prevent.
     */
    public function test_the_verdict_and_the_contributor_lists_survive_intact(): void
    {
        $coverage = ClientEntityBoundary::coverage($this->payload())['totals']['spend_coverage'];

        $this->assertSame('partial', $coverage['state']);
        $this->assertSame(['linkedin', 'meta', 'snapchat'], $coverage['included_contributors']);
        $this->assertSame(['linkedin', 'meta', 'snapchat', 'sandbox'], $coverage['expected_contributors']);
        $this->assertSame(['sandbox'], $coverage['excluded_contributors']);
    }

    /** The figures beside the coverage are untouched — this filters evidence, not money. */
    public function test_the_figures_are_not_touched(): void
    {
        $this->assertSame(9437.86, ClientEntityBoundary::coverage($this->payload())['totals']['spend']);
    }

    /**
     * Recognised by SHAPE, not by key name.
     *
     * `totals` carries three coverage blocks today. A boundary that named them would ship an
     * exception message the day a fourth is added, so a block at an unfamiliar key and an unfamiliar
     * depth must be filtered exactly the same.
     */
    public function test_a_coverage_block_at_an_unfamiliar_key_is_filtered_too(): void
    {
        $filtered = ClientEntityBoundary::coverage([
            'store_funnel' => ['deep' => ['lead_coverage' => [
                'state' => 'partial',
                'reasons' => ['zid' => 'The last sync failed: token expired.'],
            ]]],
        ]);

        $this->assertSame([], $filtered['store_funnel']['deep']['lead_coverage']['reasons']);
        $this->assertSame('partial', $filtered['store_funnel']['deep']['lead_coverage']['state']);
    }

    /**
     * And a map that merely HAS a `reasons` key is not a coverage block. Filtering on one key alone
     * would silently empty an unrelated structure; the pair `state` + `reasons` is what identifies one.
     */
    public function test_an_unrelated_structure_carrying_a_reasons_key_is_left_alone(): void
    {
        $filtered = ClientEntityBoundary::coverage([
            'recommendation' => ['reasons' => ['budget is ahead of plan']],
        ]);

        $this->assertSame(['budget is ahead of plan'], $filtered['recommendation']['reasons']);
    }
}
