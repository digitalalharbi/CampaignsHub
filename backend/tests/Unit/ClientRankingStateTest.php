<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\ClientWorkspaces\Services\ClientAnalyticsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AGGREGATION-TRUTH-001 — which silence a client surface is looking at.
 *
 * `pickCampaign()` ranks campaigns that spent, and «spent» is the CONVERTED figure. On an account
 * whose money was never converted no campaign qualifies, best and worst both come back null, and the
 * two panels simply stopped appearing — with nothing to separate «no campaign stands out» from «we
 * hold this money and cannot rank it in your currency».
 *
 * The ranking genuinely cannot be done on withheld money: a ROAS from a withheld denominator is a
 * fabricated ratio. So the reason is published instead, and the surface can say which silence it is.
 */
final class ClientRankingStateTest extends TestCase
{
    /** @param list<array<string,mixed>> $campaigns */
    private function state(array $campaigns): string
    {
        $m = new ReflectionMethod(ClientAnalyticsService::class, 'rankingState');
        $m->setAccessible(true);

        return (string) $m->invoke(
            (new \ReflectionClass(ClientAnalyticsService::class))->newInstanceWithoutConstructor(),
            $campaigns,
        );
    }

    public function test_a_campaign_that_spent_can_be_ranked(): void
    {
        self::assertSame('ranked', $this->state([
            ['spend' => 900.0, 'roas' => 2.0],
            ['spend' => 0.0],
        ]));
    }

    public function test_money_we_hold_and_cannot_state_is_not_an_empty_account(): void
    {
        self::assertSame('withheld', $this->state([
            ['spend' => 0.0, 'spend_original' => 5000.0, 'spend_withheld_rows' => 4],
        ]));
    }

    public function test_an_account_that_spent_nothing_says_so(): void
    {
        self::assertSame('no_spend', $this->state([
            ['spend' => 0.0, 'spend_original' => 0.0, 'spend_withheld_rows' => 0],
        ]));
        self::assertSame('no_spend', $this->state([]));
    }

    /** A converted figure anywhere means the ranking is possible, whatever else is withheld. */
    public function test_one_convertible_campaign_is_enough_to_rank(): void
    {
        self::assertSame('ranked', $this->state([
            ['spend' => 0.0, 'spend_original' => 5000.0, 'spend_withheld_rows' => 4],
            ['spend' => 120.0],
        ]));
    }
}
