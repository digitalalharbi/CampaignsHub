<?php

declare(strict_types=1);

namespace App\Domains\Funding\Support;

use App\Domains\Metrics\Fx\FxEngine;
use Illuminate\Support\Carbon;

/**
 * ONE-BUDGET-001 — the ONE place the budget code converts money between currencies.
 *
 * FX-APPLY-001 (builder G): it delegates to the central {@see FxEngine::convert()} — the same rate selection, cross through
 * USD, stale flag and withheld answer as every other surface. With no rate the answer is null — a figure is never guessed.
 * `$tenantId` is kept for the callers' signature; rates are platform-managed (FX-ENGINE-001), not per workspace.
 */
final class BudgetFx
{
    public function __construct(private readonly FxEngine $fx) {}

    /**
     * @return array{amount: float, rate: float, rate_date: string|null, source: string|null, kind: string|null, stale: bool, conversion: array<string, mixed>}|null
     */
    public function convert(float $amount, string $from, string $to, Carbon $date, ?string $tenantId = null): ?array
    {
        $c = $this->fx->convert($amount, strtoupper($from), strtoupper($to), $date);
        if ($c->withheld || $c->converted === null || $c->rate === null || $c->rate <= 0) {
            return null;
        }

        return [
            'amount' => round($c->converted, 2),
            'rate' => $c->rate,
            'rate_date' => $c->rateDate,
            'source' => $c->source,
            'kind' => $c->kind,
            'stale' => $c->stale,
            'conversion' => $c->toArray(),
        ];
    }

    /**
     * The rate alone (1 unit), for a caller that multiplies many figures of one pair and day.
     *
     * @return array{rate: float, rate_date: string|null, source: string|null, kind: string|null, stale: bool}|null
     */
    public function rate(string $from, string $to, Carbon $date): ?array
    {
        $c = $this->fx->convert(1, strtoupper($from), strtoupper($to), $date);
        if ($c->withheld || $c->rate === null || $c->rate <= 0) {
            return null;
        }

        return ['rate' => $c->rate, 'rate_date' => $c->rateDate, 'source' => $c->source, 'kind' => $c->kind, 'stale' => $c->stale];
    }
}
