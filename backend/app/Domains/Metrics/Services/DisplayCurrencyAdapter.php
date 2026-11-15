<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;

/**
 * CONS-I-ADAPTER — the ONE place consistency-pass builder I converts a platform-currency amount into the currency a
 * person reads it in (automation readings, approval requests).
 *
 * It sits in front of the existing FX service (`CurrencyConverter::resolveForWorkspace`: the dated rate, else the
 * workspace default, else the SAR peg). When the central `convert()` of the currency-engine batch lands, THIS class is
 * the single thing to repoint — no caller reads a rate itself.
 *
 * Nothing is invented: an unknown source currency or a missing rate gives `amount: null` and a reason, and the caller
 * shows «غير متوفرة» instead of a number in the wrong unit. Raw platform numbers are never changed.
 */
final class DisplayCurrencyAdapter
{
    public function __construct(private readonly CurrencyConverter $fx, private readonly ReportingCurrency $reporting) {}

    /** The currency figures are shown in for this project (today the reporting basis). */
    public function displayCurrency(string $projectId): string
    {
        return $this->reporting->forProject($projectId);
    }

    /**
     * @return array{amount: ?float, currency: string, converted: bool, source_amount: float, source_currency: ?string, reason: ?string}
     */
    public function toDisplay(string $tenantId, string $projectId, float $amount, ?string $from, ?Carbon $date = null): array
    {
        $to = $this->displayCurrency($projectId);
        $base = ['currency' => $to, 'source_amount' => $amount, 'source_currency' => $from !== null && trim($from) !== '' ? strtoupper(trim($from)) : null];

        if ($base['source_currency'] === null) {
            return $base + ['amount' => null, 'converted' => false, 'reason' => 'unknown_currency'];
        }
        if ($base['source_currency'] === $to) {
            return $base + ['amount' => round($amount, 2), 'converted' => false, 'reason' => null];
        }

        $rate = $this->fx->resolveForWorkspace($tenantId, $base['source_currency'], $to, $date ?? Carbon::now());
        if ($rate === null) {
            return $base + ['amount' => null, 'converted' => false, 'reason' => 'no_rate'];
        }

        return $base + ['amount' => round($amount * $rate['rate'], 2), 'converted' => true, 'reason' => null];
    }
}
