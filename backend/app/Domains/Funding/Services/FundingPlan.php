<?php

declare(strict_types=1);

namespace App\Domains\Funding\Services;

use App\Domains\Funding\Support\FundingMoney;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * BUDGET-MODES-001 — the per-platform plan of a budget, and its measured comparison with the real spend.
 *
 * A plan is the planned share of each platform. It is entered as amounts OR percentages (never mixed) and must add up
 * to the budget (amounts) or to 100 (percentages). What is STORED is the share only — [{provider, percent}] — and the
 * amount of each platform is always derived from the budget as it stands (`withAmounts`), so a renewal or an edit of
 * the budget can never leave a plan that no longer adds up. `amount` is null while the budget has no amount (a credit
 * budget with no ceiling): such a plan is a share, not a sum.
 *
 * The comparison states only what was measured:
 *  - `share_used`   spent ÷ planned for that platform — «near» from 90% and «over» from 100%, the same 0.9 / 1.0 the
 *    repo's budget alert uses (AlertEvaluator, spend ÷ budget ratio);
 *  - `pace` / `projected` — the last 7 full days' average, and spent + pace × the days left to the period's end
 *    (only when the period has an end and the platform has a pace);
 *  - `time_ratio`  spent ÷ (planned × the part of the period that has passed) — «ahead» / «behind» are chips only,
 *    never an alert. The repo has no time-pacing band; ±20% (`PACE_BAND`) is the one number introduced here, and
 *    «behind» is not judged in the first quarter of the period, when a day or two says nothing.
 * No cause is ever stated: the chip says the figure, not why.
 */
final class FundingPlan
{
    public const NEAR = 0.9;

    public const OVER = 1.0;

    public const PACE_BAND = 0.2;

    private const TOLERANCE = 0.01;

    /**
     * @param  array<int, mixed>  $rows  [{provider, amount?|percent?}]
     * @return list<array{provider: string, percent: float}>
     */
    public function normalise(array $rows, float $approved): array
    {
        if ($rows === [] || count($rows) > 12) {
            throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_rows')]]);
        }

        $byAmount = null;
        $seen = [];
        $clean = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $provider = strtolower(trim((string) ($row['provider'] ?? '')));
            if (preg_match('/^[a-z0-9_]{2,32}$/', $provider) !== 1 || isset($seen[$provider])) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_provider')]]);
            }
            $seen[$provider] = true;

            $hasAmount = isset($row['amount']) && $row['amount'] !== '';
            $hasPercent = isset($row['percent']) && $row['percent'] !== '';
            if ($hasAmount === $hasPercent) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_unit')]]);
            }
            if ($byAmount !== null && $byAmount !== $hasAmount) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_unit')]]);
            }
            $byAmount = $hasAmount;

            $value = (float) ($hasAmount ? $row['amount'] : $row['percent']);
            if (! is_finite($value) || $value <= 0 || $value > 1_000_000_000) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_value')]]);
            }
            $clean[] = ['provider' => $provider, 'value' => round($value, 2)];
        }

        $sum = array_sum(array_column($clean, 'value'));
        if ($byAmount) {
            if ($approved <= 0) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_needs_amount')]]);
            }
            if (abs($sum - $approved) > self::TOLERANCE) {
                throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_sum_amount', ['sum' => FundingMoney::plain($sum), 'budget' => FundingMoney::plain($approved)])]]);
            }

            return $this->closed(array_map(static fn (array $c): array => ['provider' => $c['provider'], 'value' => $c['value'] / $approved * 100], $clean));
        }

        if (abs($sum - 100) > self::TOLERANCE) {
            throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_sum_percent')]]);
        }

        return $this->closed($clean);
    }

    /**
     * Shares to 4 decimals, the last one taking what the rounding left so they add up to exactly 100.
     *
     * @param  list<array{provider: string, value: float}>  $rows
     * @return list<array{provider: string, percent: float}>
     */
    private function closed(array $rows): array
    {
        $out = [];
        $left = 100.0;
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $percent = $i === $last ? round($left, 4) : round((float) $row['value'], 4);
            $left = round($left - $percent, 4);
            $out[] = ['provider' => $row['provider'], 'percent' => $percent];
        }

        return $out;
    }

    /**
     * The shares in the budget's amount (the last row absorbs the rounding so the amounts add up).
     *
     * @param  list<array{provider: string, percent: float}>  $plan
     * @return list<array{provider: string, percent: float, amount: float|null}>
     */
    public function withAmounts(array $plan, float $approved): array
    {
        $out = [];
        $left = round($approved, 2);
        $last = count($plan) - 1;
        foreach ($plan as $i => $row) {
            $amount = $approved > 0 ? ($i === $last ? $left : round($approved * (float) $row['percent'] / 100, 2)) : null;
            if ($amount !== null) {
                $left = round($left - $amount, 2);
            }
            $out[] = ['provider' => $row['provider'], 'percent' => round((float) $row['percent'], 4), 'amount' => $amount];
        }

        return $out;
    }

    /**
     * The one-click «أعد التوزيع»: what was spent stays where it was spent; the budget not yet spent is split among the
     * platforms in proportion to what each still had left of its own plan (a platform already past its share gets none).
     * With nothing left of any plan, the unspent budget follows the original shares.
     *
     * @param  list<array{provider: string, percent: float, amount: float|null}>  $plan
     * @param  array<string, float>  $spentBy  provider => SAR spent in the period
     * @return list<array{provider: string, percent: float}>
     */
    public function rebalance(array $plan, array $spentBy, float $total): array
    {
        if ($total <= 0) {
            throw ValidationException::withMessages(['platform_plan' => [__('funding.plan_needs_amount')]]);
        }

        $spentTotal = array_sum($spentBy);
        $unspent = max(0.0, $total - $spentTotal);
        $room = [];
        foreach ($plan as $row) {
            $room[$row['provider']] = max(0.0, (float) ($row['amount'] ?? 0) - ($spentBy[$row['provider']] ?? 0.0));
        }
        $roomSum = array_sum($room);

        $next = [];
        foreach ($plan as $row) {
            $p = $row['provider'];
            $share = $roomSum > 0 ? $room[$p] / $roomSum : (float) $row['percent'] / 100;
            $next[] = ['provider' => $p, 'value' => ($spentBy[$p] ?? 0.0) + $unspent * $share];
        }
        // A platform that spent outside the plan keeps a row of exactly what it spent, so no spend is left unplanned.
        foreach ($spentBy as $p => $spent) {
            if ($spent > 0 && ! isset($room[$p])) {
                $next[] = ['provider' => $p, 'value' => $spent];
            }
        }

        // Past the budget there is nothing left to share: the shares are then simply those of what was spent.
        $sum = array_sum(array_column($next, 'value'));

        return $this->closed(array_map(static fn (array $r): array => ['provider' => $r['provider'], 'value' => $sum > 0 ? $r['value'] / $sum * 100 : 0.0], $next));
    }

    /**
     * Plan against actual, one row per platform in the plan (and one per platform that spent outside it).
     *
     * @param  list<array{provider: string, percent: float, amount: float|null}>  $plan
     * @param  list<array{provider: string, spent: float, unconverted_days?: int}>  $byPlatform
     * @param  array<string, float>  $weekSpend  provider => SAR spent over the last 7 full days
     * @return list<array<string, mixed>>
     */
    public function compare(array $plan, array $byPlatform, array $weekSpend, ?Carbon $start, ?Carbon $end, Carbon $today): array
    {
        $spent = [];
        $unconverted = [];
        foreach ($byPlatform as $p) {
            $spent[$p['provider']] = (float) $p['spent'];
            $unconverted[$p['provider']] = (int) ($p['unconverted_days'] ?? 0);
        }

        $today = Carbon::parse($today->toDateString());
        $elapsed = null;
        if ($start !== null && $end !== null && $end->gte($start)) {
            $total = (int) $start->diffInDays($end) + 1;
            $passed = max(0, min($total, (int) $start->diffInDays($today, false) + 1));
            $elapsed = $total > 0 ? $passed / $total : null;
        }
        $daysLeft = $end !== null ? max(0, (int) $today->diffInDays($end, false)) : null;

        $rows = [];
        $planned = [];
        foreach ($plan as $row) {
            $planned[$row['provider']] = $row;
        }
        foreach ($spent as $provider => $s) {
            if (! isset($planned[$provider]) && $s > 0) {
                $planned[$provider] = ['provider' => $provider, 'percent' => 0.0, 'amount' => 0.0, 'unplanned' => true];
            }
        }

        foreach ($planned as $provider => $row) {
            $amount = $row['amount'] === null ? null : (float) $row['amount'];
            $s = round($spent[$provider] ?? 0.0, 2);
            $pace = isset($weekSpend[$provider]) && $weekSpend[$provider] > 0 ? round($weekSpend[$provider] / 7, 2) : null;
            $shareUsed = $amount !== null && $amount > 0 ? round($s / $amount, 4) : null;
            $expected = ($amount !== null && $elapsed !== null) ? round($amount * $elapsed, 2) : null;
            $timeRatio = ($expected !== null && $expected > 0) ? round($s / $expected, 4) : null;

            $flag = match (true) {
                ! empty($row['unplanned']) => 'unplanned',
                $shareUsed !== null && $shareUsed >= self::OVER => 'over',
                $shareUsed !== null && $shareUsed >= self::NEAR => 'near',
                $timeRatio !== null && $timeRatio > 1 + self::PACE_BAND => 'ahead',
                $timeRatio !== null && $elapsed >= 0.25 && $timeRatio < 1 - self::PACE_BAND => 'behind',
                default => null,
            };

            $rows[] = [
                'provider' => $provider,
                'percent' => (float) $row['percent'],
                'planned' => $amount,
                'spent' => $s,
                'share_used' => $shareUsed,
                'pace_per_day' => $pace,
                'projected' => ($pace !== null && $daysLeft !== null) ? round($s + $pace * $daysLeft, 2) : null,
                'expected_to_date' => $expected,
                'variance' => $expected !== null ? round($s - $expected, 2) : null,
                'flag' => $flag,
                'unconverted_days' => $unconverted[$provider] ?? 0,
            ];
        }

        return $rows;
    }

    /** The platforms whose own share is near or over — what the notifier says, once each. */
    public function alerts(array $comparison): array
    {
        return array_values(array_filter($comparison, static fn (array $r): bool => in_array($r['flag'], ['near', 'over'], true)));
    }
}
