<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Reports\Services\CreativeRankingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * CREATIVE-RANK-001 — every consumer answers «best creative» with the same metric.
 *
 * Three services ranked creatives with three private sorts over three different metric sets. This is
 * the test that stops a fourth appearing, and stops any of them quietly drifting back: it asks the
 * consumer what it would rank by, asks the contract the same question, and requires the answers to
 * match for every objective.
 *
 * A consumer that reintroduces its own map fails here, which is the point.
 */
final class CreativeRankingParityTest extends TestCase
{
    /** The report's own objective vocabulary, and the canonical family each one means. */
    private const REPORT_OBJECTIVES = [
        'awareness' => ObjectiveFamily::Awareness,
        'traffic' => ObjectiveFamily::Traffic,
        'engagement' => ObjectiveFamily::Engagement,
        'video' => ObjectiveFamily::Video,
        'leads' => ObjectiveFamily::Leads,
        'sales' => ObjectiveFamily::Sales,
        'app_installs' => ObjectiveFamily::App,
    ];

    private function strategyFor(string $objective): array
    {
        $m = new ReflectionMethod(CreativeRankingService::class, 'strategy');
        $m->setAccessible(true);

        return $m->invoke(new CreativeRankingService, $objective);
    }

    public function test_the_report_ranks_by_the_contracts_metric_for_every_objective(): void
    {
        foreach (self::REPORT_OBJECTIVES as $objective => $family) {
            [$key] = $this->strategyFor($objective);

            $this->assertSame(
                RankingMetric::forObjective($family)['primary'],
                $key,
                "CreativeRankingService ranks '{$objective}' by '{$key}', which is not the contract's primary. "
                .'A consumer with its own idea of the metric is how «best creative» became a different '
                .'question on every screen.'
            );
        }
    }

    public function test_the_report_sorts_in_the_direction_the_contract_states(): void
    {
        // The half that actually misleads: a descending sort on a cost metric puts the most expensive
        // creative first and calls it the winner.
        foreach (self::REPORT_OBJECTIVES as $objective => $family) {
            [$key, $direction] = $this->strategyFor($objective);

            $expected = RankingMetric::of($key)->direction === RankingDirection::LowerIsBetter ? 'asc' : 'desc';

            $this->assertSame($expected, $direction, "'{$objective}' sorts {$direction} on {$key}");
        }
    }

    public function test_every_cost_objective_ranks_ascending(): void
    {
        foreach (['awareness', 'video', 'leads', 'app_installs'] as $objective) {
            [$key, $direction] = $this->strategyFor($objective);

            $this->assertSame(RankingDirection::LowerIsBetter, RankingMetric::of($key)->direction);
            $this->assertSame('asc', $direction, "cheapest must lead for '{$objective}'");
        }
    }
}
