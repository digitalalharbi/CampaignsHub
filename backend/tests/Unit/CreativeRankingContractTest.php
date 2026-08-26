<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * CREATIVE-RANK-001 — one ranker, every objective, and «better» stated rather than assumed.
 *
 * The defect: three services ranked creatives with three private sorts over three different metric
 * sets. `leads` existed in one, `cpl` in none, so a lead campaign could not be ranked by the thing it
 * was bought for — and a plain descending sort puts the most expensive cost-per-result first and
 * calls it the winner.
 */
final class CreativeRankingContractTest extends TestCase
{
    private function ranker(): CreativeRanking
    {
        return new CreativeRanking;
    }

    public function test_every_objective_family_has_a_rankable_primary_except_the_unclassified(): void
    {
        foreach (ObjectiveFamily::cases() as $family) {
            $spec = RankingMetric::forObjective($family);

            if ($family === ObjectiveFamily::Unknown) {
                // No verdict for a campaign nobody classified — inventing one is how «best» stops meaning anything.
                $this->assertNull($spec['primary']);

                continue;
            }

            $this->assertNotNull($spec['primary'], "{$family->value} has no primary KPI");
            $this->assertTrue(
                RankingMetric::isRankable($spec['primary']),
                "{$family->value}'s primary '{$spec['primary']}' is not in the registry"
            );

            foreach ($spec['secondary'] as $s) {
                $this->assertTrue(RankingMetric::isRankable($s), "secondary '{$s}' is not in the registry");
            }
        }
    }

    public function test_a_cost_metric_ranks_cheapest_first_and_a_return_metric_ranks_highest_first(): void
    {
        // The bug a plain descending sort produces: 100 SAR per lead presented as the best creative.
        $rows = [['id' => 'expensive', 'cpl' => 100.0], ['id' => 'cheap', 'cpl' => 20.0], ['id' => 'mid', 'cpl' => 55.0]];
        $out = $this->ranker()->rank($rows, ObjectiveFamily::Leads);

        $this->assertSame('cpl', $out['metric']);
        $this->assertSame(RankingDirection::LowerIsBetter, $out['direction']);
        $this->assertSame(['cheap', 'mid', 'expensive'], array_column($out['ranked'], 'id'));

        $sales = $this->ranker()->rank(
            [['id' => 'weak', 'roas' => 2.0], ['id' => 'strong', 'roas' => 8.0]],
            ObjectiveFamily::Sales,
        );
        $this->assertSame(['strong', 'weak'], array_column($sales['ranked'], 'id'));
    }

    public function test_an_undeclared_metric_fails_closed_instead_of_defaulting(): void
    {
        // Defaulting to higher-is-better is how a rising cost gets printed as an improvement.
        $this->expectException(InvalidArgumentException::class);
        RankingMetric::of('cost_per_something_nobody_declared');
    }

    public function test_frequency_is_deliberately_not_rankable(): void
    {
        // Rising frequency is fatigue on prospecting and reach working on retargeting. A direction
        // would make half of all campaigns rank backwards.
        $this->assertFalse(RankingMetric::isRankable('frequency'));
    }

    public function test_a_creative_the_platform_never_reported_is_excluded_not_ranked_last(): void
    {
        $rows = [['id' => 'measured', 'ctr' => 0.04], ['id' => 'silent', 'ctr' => null]];
        $out = $this->ranker()->rank($rows, ObjectiveFamily::Traffic);

        $this->assertSame(['measured'], array_column($out['ranked'], 'id'));
        $this->assertSame('silent', $out['excluded'][0]['row']['id']);
        $this->assertSame(CreativeRanking::NOT_REPORTED, $out['excluded'][0]['reason']);
    }

    public function test_a_cost_ranking_excludes_a_creative_whose_money_is_not_comparable(): void
    {
        // Partial spend, or two currencies on one axis: not a smaller number, a different question.
        $rows = [
            ['id' => 'comparable', 'cpa' => 30.0],
            ['id' => 'mixed', 'cpa' => 5.0, 'money_comparable' => false],
        ];
        $out = $this->ranker()->rank($rows, ObjectiveFamily::Sales, 'cpa');

        $this->assertSame(['comparable'], array_column($out['ranked'], 'id'));
        $this->assertSame(CreativeRanking::MONEY_NOT_COMPARABLE, $out['excluded'][0]['reason']);
    }

    public function test_a_non_money_ranking_is_unaffected_by_money_comparability(): void
    {
        // CTR is not spend-derived; a mixed-currency scope has no bearing on it.
        $rows = [['id' => 'a', 'ctr' => 0.01, 'money_comparable' => false], ['id' => 'b', 'ctr' => 0.05]];
        $out = $this->ranker()->rank($rows, ObjectiveFamily::Traffic);

        $this->assertSame(['b', 'a'], array_column($out['ranked'], 'id'));
        $this->assertSame([], $out['excluded']);
    }

    public function test_an_unclassified_objective_ranks_nothing_and_says_why(): void
    {
        $out = $this->ranker()->rank([['id' => 'a', 'spend' => 10.0]], ObjectiveFamily::Unknown);

        $this->assertNull($out['metric']);
        $this->assertSame([], $out['ranked']);
        $this->assertSame(CreativeRanking::NO_OBJECTIVE, $out['excluded'][0]['reason']);
    }

    public function test_worst_is_drawn_only_from_what_was_measured(): void
    {
        // Telling a client to stop a creative the platform never reported would be advice from an absence.
        $rows = [
            ['id' => 'best', 'roas' => 9.0], ['id' => 'good', 'roas' => 6.0],
            ['id' => 'poor', 'roas' => 1.0], ['id' => 'silent', 'roas' => null],
        ];
        $out = $this->ranker()->bestAndWorst($rows, ObjectiveFamily::Sales, take: 2);

        $this->assertSame(['best', 'good'], array_column($out['best'], 'id'));
        $this->assertSame(['poor'], array_column($out['worst'], 'id'));
        $this->assertSame(3, $out['ranked_count']);
        $this->assertSame(1, $out['excluded_count']);
    }

    public function test_best_and_worst_never_overlap_on_a_short_list(): void
    {
        $rows = [['id' => 'a', 'roas' => 5.0], ['id' => 'b', 'roas' => 3.0]];
        $out = $this->ranker()->bestAndWorst($rows, ObjectiveFamily::Sales, take: 5);

        $this->assertSame(['a', 'b'], array_column($out['best'], 'id'));
        $this->assertSame([], $out['worst'], 'two creatives cannot be both the best and the worst');
    }

    public function test_an_alternative_metric_may_override_the_objective_primary(): void
    {
        // «Rank by spend», and the lead-quality / business-outcome modes: different questions about
        // the same creatives, not a different engine.
        $rows = [['id' => 'small', 'spend' => 100.0], ['id' => 'big', 'spend' => 900.0]];
        $out = $this->ranker()->rank($rows, ObjectiveFamily::Sales, 'spend');

        $this->assertSame('spend', $out['metric']);
        $this->assertSame(['big', 'small'], array_column($out['ranked'], 'id'));
    }
}
