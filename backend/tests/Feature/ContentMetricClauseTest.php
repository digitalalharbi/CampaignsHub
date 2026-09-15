<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Services\CreativeMetrics;
use Tests\TestCase;

/**
 * Owner defects 94a–94d — the Content clauses, asserted across the WHOLE vocabulary.
 *
 * The owner still observes missing and inconsistent Content metrics on Production, and the existing
 * coverage checks one objective at a time. One objective at a time is how a vocabulary of fourteen
 * grows a hole: `app_installs` was pinned because it had already gone wrong once, and nothing asked
 * the same question of `store_visits`.
 *
 * These are exhaustive by construction — they enumerate the enum — so an objective added later is
 * covered on the day it is added rather than the day someone remembers.
 */
final class ContentMetricClauseTest extends TestCase
{
    private CreativeMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(CreativeMetrics::class);
    }

    /**
     * 94a — «Spend remains visible regardless of objective».
     *
     * The owner states it as a rule and it is stated here as one: spend is an operational fact, not
     * an objective's opinion. A buy nobody agreed a result definition for still cost money, and the
     * one number that is always true must never be the one an objective filters away.
     */
    public function test_every_objective_headlines_spend(): void
    {
        foreach (CampaignObjective::cases() as $objective) {
            $this->assertContains(
                'spend',
                $this->metrics->headline($objective->value),
                "objective «{$objective->value}» does not headline spend",
            );
        }

        // Including the absence of one: an unclassified creative still spent money.
        $this->assertContains('spend', $this->metrics->headline(null));
        $this->assertContains('spend', $this->metrics->headline('a-provider-word-nobody-mapped'));
    }

    /**
     * 94c/94d — nothing is promised that nothing computes.
     *
     * A family headlines a list of metric keys and `derive()` is what produces them. If a family ever
     * names a key `derive()` does not emit, the card asks for a figure that can never arrive and the
     * cell reads «—» forever — indistinguishable, to a reader, from a provider that did not report.
     * That is the exact confusion the owner's «unavailable vs zero» rule exists to prevent, arriving
     * from our side instead of the platform's.
     */
    public function test_no_family_headlines_a_metric_the_engine_never_produces(): void
    {
        $producible = $this->producibleKeys();
        $orphans = [];

        foreach (ObjectiveFamily::cases() as $family) {
            foreach ($family->headlineMetrics() as $metric) {
                if (! in_array($metric, $producible, true)) {
                    $orphans[] = "{$family->value} → {$metric}";
                }
            }
        }

        $this->assertSame([], $orphans, 'a family headlines a metric the engine never produces: '.implode(', ', $orphans));
    }

    /**
     * 94b/94c — a result metric and its cost travel together.
     *
     * «Results» without «Cost per result» is half an answer: it says a creative produced twenty of
     * something and refuses to say what each one cost, which is the figure a buying decision is made
     * on. Where a family names a result, it must name that result's cost too.
     */
    public function test_a_family_that_names_a_result_also_names_its_cost(): void
    {
        /*
         * The family's OWN result, not every metric it lists.
         *
         * The first version of this asked «does each metric have its cost» and flagged four families
         * for naming `impressions` or `clicks` without `cpm`/`cpc`. Those are context, deliberately:
         * an engagement buy shows impressions to say how far it reached and is judged on `cpe`. A
         * rule that fires on context would be satisfied by padding every card with costs nobody is
         * buying on, which is the opposite of the owner's «few primary KPIs».
         */
        $resultAndItsCost = [
            ObjectiveFamily::Awareness->value => ['impressions', 'cpm'],
            ObjectiveFamily::Traffic->value => ['clicks', 'cpc'],
            ObjectiveFamily::Engagement->value => ['engagements', 'cpe'],
            ObjectiveFamily::Video->value => ['video_views', 'cost_per_view'],
            ObjectiveFamily::Leads->value => ['leads', 'cpl'],
            ObjectiveFamily::Sales->value => ['orders', 'cpa'],
            ObjectiveFamily::App->value => ['installs', 'cpi'],
        ];

        foreach ($resultAndItsCost as $family => [$result, $cost]) {
            $metrics = ObjectiveFamily::from($family)->headlineMetrics();

            $this->assertContains($result, $metrics, "family «{$family}» does not name its own result");
            $this->assertContains($cost, $metrics, "family «{$family}» names {$result} without {$cost} — half an answer");
        }
    }

    /**
     * 94d — a figure the row cannot answer is withheld rather than promised.
     *
     * `supportable()` is what stops an ad-grain row being asked for a creative-grain figure. Given a
     * row that reports nothing, the headline must shrink rather than list metrics whose values will
     * all be null — a card of «—» teaches a reader that the product does not know anything.
     */
    public function test_a_row_that_reports_nothing_does_not_get_a_card_full_of_dashes(): void
    {
        $empty = ['grain' => 'creative', 'reported' => []];

        foreach (CampaignObjective::cases() as $objective) {
            $withRow = $this->metrics->headline($objective->value, $empty);
            $withoutRow = $this->metrics->headline($objective->value);

            $this->assertLessThanOrEqual(
                count($withoutRow),
                count($withRow),
                "objective «{$objective->value}» promises MORE metrics for a row that reports nothing",
            );
        }
    }

    /**
     * Every key `derive()` can put on a figures array.
     *
     * Read from the service's own constants through reflection rather than restated here: a list
     * copied into a test is a list that stops matching the code it guards.
     *
     * @return list<string>
     */
    private function producibleKeys(): array
    {
        $reflection = new \ReflectionClass(CreativeMetrics::class);
        $constants = $reflection->getConstants();

        /** @var array<string, string> $sums */
        $sums = $constants['SUMS'];
        /** @var array<string, string> $adGrainSums */
        $adGrainSums = $constants['AD_GRAIN_SUMS'];

        /*
         * FOUR constants, not three. `AVERAGED` was missed by the first version of this and it
         * flagged `awareness → frequency` — a metric the service does produce, as an `AVG()` column
         * rather than a derived figure, precisely because a frequency summed across days grows with
         * the window and means nothing. The test was incomplete; the code was right.
         */
        return array_values(array_unique([
            ...array_keys($sums),
            ...array_keys($adGrainSums),
            ...$constants['DERIVED'],
            ...$constants['AD_GRAIN_DERIVED'],
            ...$constants['AVERAGED'],
        ]));
    }
}
