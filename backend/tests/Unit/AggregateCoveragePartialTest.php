<?php

namespace Tests\Unit;

use App\Domains\Metrics\Coverage\AggregateCoverage;
use App\Domains\Metrics\Coverage\ContributionState;
use App\Domains\Reports\Services\ClientEntityBoundary;
use PHPUnit\Framework\TestCase;

/**
 * CAMPAIGN-KPI-COVERAGE-001 — a contributor that reported, but not through the end of the window,
 * is named as such on the payload, with the date it reported through.
 */
class AggregateCoveragePartialTest extends TestCase
{
    public function test_a_partial_contributor_is_listed_with_the_date_it_reported_through(): void
    {
        $coverage = new AggregateCoverage(
            ['meta' => ContributionState::Partial, 'snapchat' => ContributionState::ReportedValue],
            ['meta' => 'Reported through 2026-09-27; this window ends 2026-10-10.'],
            ['meta' => '2026-09-27'],
        );

        $out = $coverage->toArray();

        $this->assertSame('partial', $out['state']);
        $this->assertSame(['meta'], $out['partial_contributors']);
        $this->assertSame(['meta'], $out['excluded_contributors']);
        $this->assertSame(['snapchat'], $out['included_contributors']);
        $this->assertSame(['meta' => '2026-09-27'], $out['reported_through']);
        $this->assertSame([], $coverage->stale());
    }

    public function test_a_stale_contributor_is_not_a_partial_one(): void
    {
        $coverage = new AggregateCoverage(
            ['meta' => ContributionState::Stale],
            ['meta' => 'Synced only through 2026-09-20; this window ends 2026-10-10.'],
            ['meta' => '2026-09-20'],
        );

        $out = $coverage->toArray();

        $this->assertSame([], $out['partial_contributors']);
        $this->assertSame(['meta'], $out['stale_contributors']);
        $this->assertSame(['meta' => '2026-09-20'], $out['reported_through']);
    }

    public function test_the_client_boundary_keeps_the_date_and_blanks_the_sentence(): void
    {
        $block = (new AggregateCoverage(
            ['meta' => ContributionState::Partial],
            ['meta' => 'Reported through 2026-09-27; this window ends 2026-10-10.'],
            ['meta' => '2026-09-27'],
        ))->toArray();

        $filtered = ClientEntityBoundary::coverage(['totals' => ['spend' => 1.0, 'coverage' => $block]]);

        $this->assertSame([], $filtered['totals']['coverage']['reasons']);
        $this->assertSame(['meta' => '2026-09-27'], $filtered['totals']['coverage']['reported_through']);
        $this->assertSame(['meta'], $filtered['totals']['coverage']['partial_contributors']);
    }

    public function test_a_complete_coverage_states_empty_lists_not_missing_keys(): void
    {
        $out = AggregateCoverage::complete()->toArray();

        $this->assertSame([], $out['partial_contributors']);
        $this->assertSame([], $out['reported_through']);
    }
}
