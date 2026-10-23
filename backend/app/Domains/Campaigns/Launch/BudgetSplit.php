<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Launch;

use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * LAUNCH-BUDGET-001 — one daily budget split across ad accounts, in percentages that total exactly 100 and amounts that total
 * exactly the budget.
 *
 * Pure arithmetic over a given budget: it saves nothing and sends nothing. Three things are decided here and never left to the
 * screen:
 *
 *   - The shares total 100. Accounts without a share split what the named ones leave, evenly; if every account is named they
 *     must total 100 (within a thousandth) or the request is refused — a split that does not add up is not clamped into one.
 *   - The amounts total the budget to the minor unit. Each amount is floored to cents and the cents left over go, one each,
 *     to the largest remainders, so «100.00 over three accounts» is 33.34 + 33.33 + 33.33 and not 99.99.
 *   - A currency that is not the budget's needs a published rate for that pair. A missing rate is a refusal for THAT account,
 *     named, never a silent 1:1. An account's own minimum or maximum is reported as a violation; nothing is clamped, because
 *     moving money between accounts is a decision, not a rounding step.
 */
final class BudgetSplit
{
    public const MODES = ['manual', 'assisted', 'automated'];

    public function __construct(private readonly CurrencyConverter $rates) {}

    /**
     * @param  list<array{id:string, currency:string, share?:float|int|string|null, min?:float|int|null, max?:float|int|null}>  $slots
     * @return array{ok:bool, total:float, currency:string, slots:list<array<string,mixed>>, violations:list<array<string,string>>}
     */
    public function allocate(float $total, string $currency, array $slots, ?Carbon $on = null): array
    {
        if ($total <= 0 || $slots === []) {
            throw new InvalidArgumentException('A budget split needs a positive total and at least one account.');
        }
        $currency = strtoupper($currency);
        $on ??= Carbon::now();

        $shares = $this->shares($slots);
        $cents = $this->cents((int) round($total * 100), $shares);

        $out = [];
        $violations = [];
        foreach ($slots as $i => $slot) {
            $amount = round($cents[$i] / 100, 2);
            $accountCurrency = strtoupper((string) $slot['currency']);
            $rate = $this->rates->resolve($currency, $accountCurrency, $on);
            $inAccount = $rate === null ? null : round($amount * $rate['rate'], 2);

            if ($rate === null) {
                $violations[] = ['id' => (string) $slot['id'], 'kind' => 'missing_rate'];
            } else {
                if (isset($slot['min']) && $inAccount < (float) $slot['min']) {
                    $violations[] = ['id' => (string) $slot['id'], 'kind' => 'below_min'];
                }
                if (isset($slot['max']) && $inAccount > (float) $slot['max']) {
                    $violations[] = ['id' => (string) $slot['id'], 'kind' => 'above_max'];
                }
            }

            $out[] = [
                'id' => (string) $slot['id'],
                'share_pct' => $shares[$i],
                'amount' => $amount,
                'currency' => $currency,
                'amount_in_account_currency' => $inAccount,
                'account_currency' => $accountCurrency,
                'rate' => $rate === null ? null : ['value' => $rate['rate'], 'date' => $rate['rate_date'], 'source' => $rate['source']],
            ];
        }

        return ['ok' => $violations === [], 'total' => round($total, 2), 'currency' => $currency, 'slots' => $out, 'violations' => $violations];
    }

    /**
     * Percentages in hundredths of a percent, summing to exactly 10000 → returned as floats with two decimals.
     *
     * @param  list<array<string,mixed>>  $slots
     * @return list<float>
     */
    private function shares(array $slots): array
    {
        $named = 0;
        $open = 0;
        foreach ($slots as $s) {
            isset($s['share']) && $s['share'] !== '' ? $named += (int) round((float) $s['share'] * 100) : $open++;
            if (isset($s['share']) && $s['share'] !== '' && (float) $s['share'] < 0) {
                throw new InvalidArgumentException('shares_must_not_be_negative');
            }
        }
        if ($open === 0) {
            if (abs($named - 10000) > 10) {
                throw new InvalidArgumentException('shares_must_sum_to_100');
            }
            // Within a thousandth: hand the difference to the largest share so the total is exactly 100.
            $units = array_map(static fn (array $s): int => (int) round((float) $s['share'] * 100), $slots);
            $units[array_search(max($units), $units, true)] += 10000 - $named;

            return array_map(static fn (int $u): float => (float) ($u / 100), $units);
        }
        $left = 10000 - $named;
        if ($left < 0 || ($left === 0 && $open > 0)) {
            throw new InvalidArgumentException('shares_leave_nothing_for_the_rest');
        }
        $each = intdiv($left, $open);
        $extra = $left - $each * $open;
        $units = [];
        foreach ($slots as $s) {
            if (isset($s['share']) && $s['share'] !== '') {
                $units[] = (int) round((float) $s['share'] * 100);
            } else {
                $units[] = $each + ($extra-- > 0 ? 1 : 0);
            }
        }

        return array_map(static fn (int $u): float => (float) ($u / 100), $units);
    }

    /**
     * Whole cents per slot totalling exactly $totalCents: floor each, then give the remainder out by largest fractional part.
     *
     * @param  list<float>  $shares
     * @return list<int>
     */
    private function cents(int $totalCents, array $shares): array
    {
        $exact = array_map(static fn (float $p): float => $totalCents * $p / 100, $shares);
        $floor = array_map(static fn (float $x): int => (int) floor($x + 1e-9), $exact);
        $left = $totalCents - array_sum($floor);
        $order = array_keys($exact);
        usort($order, static fn (int $a, int $b): int => (($exact[$b] - $floor[$b]) <=> ($exact[$a] - $floor[$a])) ?: ($a <=> $b));
        foreach (array_slice($order, 0, max(0, $left)) as $i) {
            $floor[$i]++;
        }

        return $floor;
    }
}
