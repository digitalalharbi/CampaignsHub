<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Fx\FxDisplay;
use App\Domains\Metrics\Fx\FxEngine;
use Illuminate\Support\Carbon;

/**
 * CONS-I-ADAPTER — the ONE place consistency-pass builder I converts a platform-currency amount into the currency a
 * person reads it in (automation readings, approval requests).
 *
 * FX-APPLY-001 (builder G): repointed to the central {@see FxEngine::convert()}; the display currency is the workspace's
 * ({@see FxDisplay::currencyForProject()} → FxPolicy, USD by default). No caller reads a rate itself.
 *
 * Nothing is invented: an unknown source currency or a missing rate gives `amount: null` and a reason, and the caller
 * shows «—» with the rates link instead of a number in the wrong unit. Raw platform numbers are never changed.
 */
final class DisplayCurrencyAdapter
{
    public function __construct(private readonly FxEngine $fx, private readonly FxDisplay $display) {}

    /** The currency figures are shown in for this project: the owning workspace's display currency. */
    public function displayCurrency(string $projectId): string
    {
        return $this->display->currencyForProject($projectId);
    }

    /**
     * @return array{amount: ?float, currency: string, converted: bool, source_amount: float, source_currency: ?string, reason: ?string, stale: bool, conversion: ?array<string, mixed>}
     */
    public function toDisplay(string $tenantId, string $projectId, float $amount, ?string $from, ?Carbon $date = null): array
    {
        $to = $this->displayCurrency($projectId);

        return $this->to($to, $amount, $from, $date);
    }

    /**
     * The same, into a currency the caller names (a rule's own currency).
     *
     * @return array{amount: ?float, currency: string, converted: bool, source_amount: float, source_currency: ?string, reason: ?string, stale: bool, conversion: ?array<string, mixed>}
     */
    public function to(string $to, float $amount, ?string $from, ?Carbon $date = null): array
    {
        $to = strtoupper($to);
        $base = ['currency' => $to, 'source_amount' => $amount, 'source_currency' => $from !== null && trim($from) !== '' ? strtoupper(trim($from)) : null];

        if ($base['source_currency'] === null) {
            return $base + ['amount' => null, 'converted' => false, 'reason' => 'unknown_currency', 'stale' => false, 'conversion' => null];
        }

        $c = $this->fx->convert($amount, $base['source_currency'], $to, $date ?? Carbon::now());
        if ($c->withheld || $c->converted === null) {
            return $base + ['amount' => null, 'converted' => false, 'reason' => 'no_rate', 'stale' => false, 'conversion' => $c->toArray()];
        }

        return $base + [
            'amount' => round($c->converted, 2),
            'converted' => $base['source_currency'] !== $to,
            'reason' => null,
            'stale' => $c->stale,
            'conversion' => $c->toArray(),
        ];
    }
}
