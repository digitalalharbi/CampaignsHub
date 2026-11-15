<?php

declare(strict_types=1);

namespace App\Domains\Funding\Support;

use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;

/**
 * ONE-BUDGET-001 — the ONE place the budget code converts money between currencies.
 *
 * Today it asks the existing FX service (`CurrencyConverter::resolveForWorkspace`: a dated rate, else the workspace's
 * default USD↔SAR rate, else nothing). A central `convert(amount, from, to, date)` is being built on another branch
 * (consistency pass, builder F); when it lands, this adapter is the only call to rewire. With no rate the answer is
 * null — a figure is never guessed.
 */
final class BudgetFx
{
    public function __construct(private readonly CurrencyConverter $fx) {}

    /** @return array{amount: float, rate: float, rate_date: string|null, source: string|null}|null */
    public function convert(float $amount, string $from, string $to, Carbon $date, string $tenantId): ?array
    {
        $rate = $this->fx->resolveForWorkspace($tenantId, strtoupper($from), strtoupper($to), $date);
        if ($rate === null || ! isset($rate['rate']) || (float) $rate['rate'] <= 0) {
            return null;
        }

        return [
            'amount' => round($amount * (float) $rate['rate'], 2),
            'rate' => (float) $rate['rate'],
            'rate_date' => isset($rate['rate_date']) ? (string) $rate['rate_date'] : null,
            'source' => isset($rate['source']) ? (string) $rate['source'] : null,
        ];
    }
}
