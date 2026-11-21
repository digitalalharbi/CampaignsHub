<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * FX-ENGINE-001 — the GOLDEN RULE: a monetary value is ONE object {amount, currency, date}.
 *
 * A bare number is not money: without its currency it cannot be marked, and without its date it cannot be converted
 * at the rate that was in force for it. Every path that shows, sums or compares money carries this and converts it
 * through {@see FxEngine::convert()}.
 */
final readonly class MoneyValue
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $date,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code: {$currency}");
        }
    }

    public static function of(float|int|string $amount, string $currency, Carbon|string $date): self
    {
        return new self((float) $amount, strtoupper(trim($currency)), $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString());
    }

    /** @return array{amount: float, currency: string, date: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'date' => $this->date];
    }
}
