<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Launch\BudgetSplit;
use App\Domains\Metrics\Models\CurrencyRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * LAUNCH-BUDGET-001 — a budget split that adds up: shares total 100, amounts total the budget to the cent, a currency without a
 * rate is refused by name, and an account's own limits are reported, never silently clamped.
 */
final class BudgetSplitTest extends TestCase
{
    use RefreshDatabase;

    private function split(float $total, array $slots, string $currency = 'SAR'): array
    {
        return app(BudgetSplit::class)->allocate($total, $currency, $slots, Carbon::parse('2026-10-20'));
    }

    private function slot(string $id, ?float $share = null, string $currency = 'SAR', array $extra = []): array
    {
        return ['id' => $id, 'currency' => $currency, 'share' => $share] + $extra;
    }

    public function test_the_amounts_total_the_budget_to_the_cent_and_the_shares_total_one_hundred(): void
    {
        $out = $this->split(100.00, [$this->slot('a'), $this->slot('b'), $this->slot('c')]);

        $this->assertSame([33.34, 33.33, 33.33], array_column($out['slots'], 'amount'));
        $this->assertEqualsWithDelta(100.00, array_sum(array_column($out['slots'], 'amount')), 0.0001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($out['slots'], 'share_pct')), 0.0001);
        $this->assertTrue($out['ok']);
    }

    public function test_named_shares_are_kept_and_the_rest_split_what_they_leave(): void
    {
        $out = $this->split(1000, [$this->slot('a', 50), $this->slot('b'), $this->slot('c')]);

        $this->assertSame([50.0, 25.0, 25.0], array_column($out['slots'], 'share_pct'));
        $this->assertSame([500.0, 250.0, 250.0], array_column($out['slots'], 'amount'));
    }

    public function test_every_share_named_must_total_one_hundred_and_is_refused_otherwise(): void
    {
        foreach ([[60, 30], [70, 40]] as [$x, $y]) {
            try {
                $this->split(100, [$this->slot('a', $x), $this->slot('b', $y)]);
                $this->fail('A split that does not add up to 100 must be refused, not scaled into one.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('shares_must_sum_to_100', $e->getMessage());
            }
        }
        $ok = $this->split(100, [$this->slot('a', 60.0), $this->slot('b', 40.0)]);
        $this->assertSame([60.0, 40.0], array_column($ok['slots'], 'share_pct'));
    }

    public function test_named_shares_that_leave_nothing_for_an_unnamed_account_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('shares_leave_nothing_for_the_rest');
        $this->split(100, [$this->slot('a', 100), $this->slot('b')]);
    }

    public function test_an_account_in_another_currency_is_converted_at_a_published_rate_and_says_which(): void
    {
        CurrencyRate::create(['base_currency' => 'USD', 'quote_currency' => 'SAR', 'rate' => 3.75, 'rate_date' => '2026-10-01']);

        $out = $this->split(375, [$this->slot('sar'), $this->slot('usd', null, 'USD')]);

        $usd = $out['slots'][1];
        $this->assertSame('USD', $usd['account_currency']);
        $this->assertSame(187.5 / 3.75, $usd['amount_in_account_currency']);
        $this->assertSame(['value', 'date', 'source'], array_keys($usd['rate']));
        $this->assertSame('2026-10-01', $usd['rate']['date']);
        $this->assertNull($out['slots'][0]['rate']['value'] === 1.0 ? null : 'not identity');
        $this->assertTrue($out['ok']);
    }

    public function test_a_currency_with_no_published_rate_is_a_named_refusal_not_a_one_to_one(): void
    {
        $out = $this->split(100, [$this->slot('sar'), $this->slot('eur', null, 'EUR')]);

        $this->assertFalse($out['ok']);
        $this->assertSame([['id' => 'eur', 'kind' => 'missing_rate']], $out['violations']);
        $this->assertNull($out['slots'][1]['amount_in_account_currency']);
    }

    public function test_an_accounts_own_limits_are_reported_and_nothing_is_clamped(): void
    {
        $out = $this->split(100, [$this->slot('a', 90, extra: ['max' => 50]), $this->slot('b', 10, extra: ['min' => 20])]);

        $this->assertSame([['id' => 'a', 'kind' => 'above_max'], ['id' => 'b', 'kind' => 'below_min']], $out['violations']);
        $this->assertSame([90.0, 10.0], array_column($out['slots'], 'amount'), 'The split stays what was asked; moving money is a decision.');
    }

    public function test_an_empty_or_non_positive_budget_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->split(0, [$this->slot('a')]);
    }
}
