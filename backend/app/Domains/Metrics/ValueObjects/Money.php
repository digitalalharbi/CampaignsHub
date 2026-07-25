<?php

declare(strict_types=1);

namespace App\Domains\Metrics\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable money value: a decimal amount in a specific ISO-4217 currency. Conversions preserve the
 * original and return a new instance — the caller keeps both so historical figures never drift.
 */
final class Money
{
    private function __construct(
        public readonly float $amount,
        public readonly string $currency,
    ) {}

    public static function of(float|int|string $amount, string $currency): self
    {
        $code = strtoupper(trim($currency));
        if (strlen($code) !== 3) {
            throw new InvalidArgumentException("Invalid currency code: {$currency}");
        }

        return new self(round((float) $amount, 6), $code);
    }

    /** Convert to another currency using an explicit rate (1 unit of this currency = $rate of $to). */
    public function convert(string $to, float $rate): self
    {
        return self::of($this->amount * $rate, $to);
    }

    public function isZero(): bool
    {
        return abs($this->amount) < 0.0000005;
    }
}
