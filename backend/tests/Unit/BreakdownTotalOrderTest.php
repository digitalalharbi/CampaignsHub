<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\MetricsAggregator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ENTITY-RELEVANCE-ORDERING-001 — every breakdown has an order the database promised.
 *
 * ## What was wrong
 *
 * `orderCampaignRows()` was written for the campaign breakdown with the reasoning spelled out: spend
 * alone returns 0 for two rows that spent the same, which leaves them in whatever order the query
 * produced — and the query has no `ORDER BY`, so «PostgreSQL guarantees nothing about it». A project
 * full of campaigns that spent nothing is made entirely of such ties.
 *
 * That reasoning is not about campaigns. It was applied to one of the three breakdowns that need it:
 *
 *   - `byProvider()` sorted on `spend` ALONE — the list behind the platform comparison table and the
 *     spend donut on a client's own report;
 *   - `byAccount()` had no ordering whatsoever, while its tab asks «what moved between the accounts».
 *
 * ## Why this is a unit test on rows
 *
 * Proving it through the database is not possible: the rows come back in insertion order anyway, so
 * a database test passes with or without the tiebreak and would be decoration — which is the note
 * `orderCampaignRows()` already carries. Handed a deliberately scrambled list, the function either
 * imposes a total order or it does not.
 */
final class BreakdownTotalOrderTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function tied(): array
    {
        /* Every row spent the same, which is what a project before it starts looks like. */
        return [
            ['provider' => 'tiktok', 'spend' => 0.0],
            ['provider' => 'meta', 'spend' => 0.0],
            ['provider' => 'google', 'spend' => 0.0],
            ['provider' => 'snapchat', 'spend' => 0.0],
        ];
    }

    #[Test]
    public function rows_that_tie_on_spend_come_back_in_the_same_order_every_time(): void
    {
        $once = array_column(MetricsAggregator::orderBySpendThen($this->tied(), 'provider'), 'provider');

        /* The same set, handed over in a different order — the database is free to do exactly this. */
        $scrambled = array_reverse($this->tied());
        $twice = array_column(MetricsAggregator::orderBySpendThen($scrambled, 'provider'), 'provider');

        $this->assertSame($once, $twice, 'two identical requests could return the platforms in different orders');
        $this->assertSame(['google', 'meta', 'snapchat', 'tiktok'], $once, 'the tiebreak is the provider, so the order is its own');
    }

    /** Spend still decides where it can. A tiebreak that outranks the ranking is not a tiebreak. */
    #[Test]
    public function spend_still_ranks_where_the_rows_differ(): void
    {
        $rows = [
            ['provider' => 'a_small', 'spend' => 5.0],
            ['provider' => 'z_large', 'spend' => 500.0],
        ];

        $this->assertSame(
            ['z_large', 'a_small'],
            array_column(MetricsAggregator::orderBySpendThen($rows, 'provider'), 'provider'),
            'the alphabetically-later row spent ten times as much and must come first',
        );
    }

    /**
     * A row with no spend at all sorts as nothing rather than throwing.
     *
     * `byAccount()` returns rows whose money the contract may have withheld, and a null there is a
     * real state — the ordering must survive it without deciding the row spent more than everyone.
     */
    #[Test]
    public function a_row_with_no_spend_sorts_last_without_error(): void
    {
        $rows = [
            ['account_id' => 'b', 'spend' => null],
            ['account_id' => 'a', 'spend' => 10.0],
        ];

        $this->assertSame(
            ['a', 'b'],
            array_column(MetricsAggregator::orderBySpendThen($rows, 'account_id'), 'account_id'),
        );
    }

    /** The account breakdown uses the account id, so two accounts sharing a name still order. */
    #[Test]
    public function accounts_tie_on_their_id_and_never_on_their_name(): void
    {
        $rows = [
            ['account_id' => 'id-2', 'account_name' => 'Main', 'spend' => 0.0],
            ['account_id' => 'id-1', 'account_name' => 'Main', 'spend' => 0.0],
        ];

        $this->assertSame(
            ['id-1', 'id-2'],
            array_column(MetricsAggregator::orderBySpendThen($rows, 'account_id'), 'account_id'),
        );
    }
}
