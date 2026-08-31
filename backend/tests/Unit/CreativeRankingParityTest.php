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

    public function test_every_rankable_metric_can_be_named_in_a_digest(): void
    {
        /*
         * The digest turned a metric into an Arabic sentence with its own four-entry map — roas, cpa,
         * cpm, ctr — and returned null for anything else. Once the Pulse could rank on `cpl`, `cpi`,
         * `cost_per_view` or `engagement_rate`, a lead, app, video or engagement campaign would have
         * had its best creative silently unexplained: the email looks complete, minus a line nobody
         * knew to expect.
         *
         * Every metric an objective can be ranked by must therefore carry a name in the registry.
         */
        foreach (self::REPORT_OBJECTIVES as $objective => $family) {
            $layout = RankingMetric::forObjective($family);

            foreach (array_merge([$layout['primary']], $layout['secondary']) as $key) {
                if ($key === null || ! RankingMetric::isRankable($key)) {
                    continue;
                }

                $spec = RankingMetric::of($key);
                $this->assertNotSame('', $spec->labelAr, "'{$key}' has no Arabic name");
                $this->assertNotSame('', $spec->labelEn, "'{$key}' has no English name");
            }
        }
    }

    public function test_the_fallback_prefers_efficiency_over_volume(): void
    {
        /*
         * `resolveMetric` walks `secondary` in order when the primary is unreported, so that order is
         * load-bearing. Sales listed `purchases` before `cpa`, and an account whose platform returns
         * no revenue fell back to a purchase COUNT — which ranks by budget, the same trap that made
         * `reach` wrong for awareness.
         */
        $sales = RankingMetric::forObjective(ObjectiveFamily::Sales)['secondary'];
        $this->assertLessThan(
            array_search('purchases', $sales, true),
            array_search('cpa', $sales, true),
            'cost must precede volume in the fallback order',
        );

        $traffic = RankingMetric::forObjective(ObjectiveFamily::Traffic)['secondary'];
        $this->assertLessThan(array_search('clicks', $traffic, true), array_search('cpc', $traffic, true));
    }
}
