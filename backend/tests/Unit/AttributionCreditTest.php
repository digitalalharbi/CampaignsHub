<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Metrics\Services\AttributionCredit;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * ATTRIB-CREDIT-001 — how a whole order is shared across its verified marketing touchpoints. The invariant that
 * matters is that every model returns shares adding up to exactly one: a model that can create or lose a sale
 * cannot be compared with another.
 */
final class AttributionCreditTest extends TestCase
{
    private function cfg(array $over = []): array
    {
        return AttributionCredit::config($over);
    }

    /** @param list<array{0:string,1:int,2?:bool}> $list channel, days before, is_paid @return list<array{channel:string,at:Carbon,is_paid:bool}> */
    private function touches(array $list, Carbon $at): array
    {
        return array_map(static fn (array $t): array => ['channel' => $t[0], 'at' => $at->copy()->subDays($t[1]), 'is_paid' => $t[2] ?? true], $list);
    }

    public function test_every_model_adds_up_to_exactly_one_for_any_number_of_touches(): void
    {
        $at = Carbon::parse('2026-10-20 12:00:00');
        foreach (range(1, 8) as $n) {
            $list = [];
            for ($i = 0; $i < $n; $i++) {
                $list[] = [['meta', 'google', 'tiktok', 'snapchat'][$i % 4], $n - 1 - $i];
            }
            foreach (AttributionCredit::MODELS as $model) {
                $shares = AttributionCredit::split($model, $this->touches($list, $at), $at, $this->cfg());
                $this->assertEqualsWithDelta(1.0, array_sum($shares), 1e-9, "{$model} with {$n} touches");
            }
        }
    }

    public function test_no_touch_means_unattributed_in_every_model_and_nothing_is_borrowed_from_elsewhere(): void
    {
        $at = Carbon::parse('2026-10-20');
        foreach (AttributionCredit::MODELS as $model) {
            $this->assertSame(['unattributed' => 1.0], AttributionCredit::split($model, [], $at, $this->cfg()), $model);
        }
    }

    public function test_linear_is_even_and_position_based_is_forty_twenty_forty(): void
    {
        $at = Carbon::parse('2026-10-20');
        $t = $this->touches([['google', 20], ['tiktok', 10], ['snapchat', 5], ['meta', 0]], $at);

        $this->assertEqualsWithDelta(0.25, AttributionCredit::split('linear', $t, $at, $this->cfg())['google'], 1e-9);

        $pos = AttributionCredit::split('position_based', $t, $at, $this->cfg());
        $this->assertEqualsWithDelta(0.4, $pos['google'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $pos['tiktok'], 1e-9);
        $this->assertEqualsWithDelta(0.1, $pos['snapchat'], 1e-9);
        $this->assertEqualsWithDelta(0.4, $pos['meta'], 1e-9);
    }

    public function test_first_last_and_last_paid_click_pick_one_touch_each(): void
    {
        $at = Carbon::parse('2026-10-20');
        $t = $this->touches([['meta', 9], ['influencer', 4, false], ['whatsapp', 1, false]], $at);

        $this->assertSame(['meta' => 1.0], AttributionCredit::split('first_click', $t, $at, $this->cfg()));
        $this->assertSame(['whatsapp' => 1.0], AttributionCredit::split('last_click', $t, $at, $this->cfg()));
        $this->assertSame(['meta' => 1.0], AttributionCredit::split('last_paid_click', $t, $at, $this->cfg()), 'the later touches are not paid platform clicks');

        $unpaidOnly = $this->touches([['influencer', 4, false]], $at);
        $this->assertSame(['unattributed' => 1.0], AttributionCredit::split('last_paid_click', $unpaidOnly, $at, $this->cfg()));
    }

    public function test_two_touches_split_by_the_ratio_of_the_ends_and_custom_weights_are_honoured(): void
    {
        $at = Carbon::parse('2026-10-20');
        $two = $this->touches([['google', 5], ['meta', 0]], $at);

        $this->assertEqualsWithDelta(0.5, AttributionCredit::split('position_based', $two, $at, $this->cfg())['google'], 1e-9);

        $custom = AttributionCredit::split('custom_weighted', $two, $at, $this->cfg(['weights' => ['first' => 1, 'middle' => 0, 'last' => 3]]));
        $this->assertEqualsWithDelta(0.25, $custom['google'], 1e-9);
        $this->assertEqualsWithDelta(0.75, $custom['meta'], 1e-9);
    }

    public function test_time_decay_halves_the_weight_every_half_life(): void
    {
        $at = Carbon::parse('2026-10-20');
        $shares = AttributionCredit::split('time_decay', $this->touches([['google', 7], ['meta', 0]], $at), $at, $this->cfg(['half_life_days' => 7]));

        $this->assertEqualsWithDelta(1 / 3, $shares['google'], 1e-9);
        $this->assertEqualsWithDelta(2 / 3, $shares['meta'], 1e-9);
    }

    public function test_a_touch_outside_the_lookback_or_after_the_conversion_is_not_eligible(): void
    {
        $at = Carbon::parse('2026-10-20');
        $t = [
            ...$this->touches([['google', 60], ['meta', 1]], $at),
            ['channel' => 'snapchat', 'at' => $at->copy()->addHour(), 'is_paid' => true],
        ];

        $this->assertSame(['meta' => 1.0], AttributionCredit::split('linear', $t, $at, $this->cfg(['lookback_days' => 30])));

        $long = AttributionCredit::split('linear', $t, $at, $this->cfg(['lookback_days' => 90]));
        $this->assertEqualsWithDelta(0.5, $long['google'], 1e-9);
        $this->assertArrayNotHasKey('snapchat', $long, 'a touch after the order cannot have caused it');
    }

    public function test_the_input_order_does_not_change_the_answer(): void
    {
        $at = Carbon::parse('2026-10-20');
        $t = $this->touches([['google', 9], ['tiktok', 5], ['meta', 1]], $at);

        $this->assertEquals(
            AttributionCredit::split('position_based', $t, $at, $this->cfg()),
            AttributionCredit::split('position_based', array_reverse($t), $at, $this->cfg()),
        );
    }

    public function test_a_bad_configuration_is_refused_where_it_is_entered(): void
    {
        foreach ([
            ['models' => ['teleport']], ['models' => []], ['lookback_days' => 0], ['lookback_days' => 400], ['half_life_days' => 0],
            ['weights' => ['first' => -1, 'middle' => 0, 'last' => 1]], ['weights' => ['first' => 0, 'middle' => 0, 'last' => 0]], ['weights' => ['first' => 1]],
        ] as $bad) {
            try {
                AttributionCredit::config($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_weights_are_stored_as_shares_of_one_whatever_they_were_typed_as(): void
    {
        $a = AttributionCredit::config(['weights' => ['first' => 2, 'middle' => 1, 'last' => 2]]);
        $b = AttributionCredit::config(['weights' => ['first' => 40, 'middle' => 20, 'last' => 40]]);

        $this->assertEqualsWithDelta(1.0, array_sum($a['weights']), 1e-9);
        $this->assertEquals($a['weights'], $b['weights']);
    }
}
