<?php

declare(strict_types=1);

namespace App\Domains\Metrics\ValueObjects;

use App\Domains\Metrics\Enums\MoneyState;

/**
 * The resolved money composition of one metric (spend, revenue) over a scope — the ONE backend place
 * the withheld/converted/partial rule lives (PARTIAL-WITHHELD-001).
 *
 * `MetricsAggregator` coalesces a withheld `SUM(value)` to 0 so arithmetic works, and carries the
 * withheld rows' count, summed original and distinct-currency count alongside. Those four inputs are
 * everything needed to know whether a single figure exists. Callers ask `MoneyScope::of(...)` and
 * then read `amount()`/`currency()`, which return `null` for `partial`/`mixed_currency`/`absent`
 * rather than inventing a total from half the scope. Funnel, budget pacing and campaign comparison
 * all consume this, so «you have a total» has one answer and not four.
 */
final class MoneyScope
{
    private function __construct(
        public readonly MoneyState $state,
        /** The converted sum in the reporting currency, when any converted money exists (else null). */
        public readonly ?float $converted,
        /** The summed original of the withheld rows (0 when none). */
        public readonly float $original,
        /** The single currency the withheld rows agree on, when they do (else null). */
        public readonly ?string $originalCurrency,
    ) {}

    /**
     * Resolve the state from the four figures the aggregator carries for a key.
     *
     * @param  float|null  $convertedValue  `SUM(value)` — null only where a surface drops the COALESCE
     *                                      (the funnel) to mean «never reported»; 0 means measured zero.
     * @param  int  $withheldRows  count of rows with `value IS NULL AND original_amount IS NOT NULL`
     * @param  float  $original  `SUM(original_amount)` over exactly those withheld rows
     * @param  int  $currencies  distinct `original_currency` among the withheld rows
     */
    public static function of(?float $convertedValue, int $withheldRows, float $original, int $currencies, ?string $originalCurrency): self
    {
        $currency = is_string($originalCurrency) && $originalCurrency !== '' ? $originalCurrency : null;
        $hasWithheld = $withheldRows > 0 && $original > 0.0;
        $hasConverted = $convertedValue !== null && $convertedValue > 0.0;

        if ($hasWithheld && $currencies !== 1) {
            return new self(MoneyState::MixedCurrency, $convertedValue, $original, null);
        }
        if ($hasWithheld && $hasConverted) {
            return new self(MoneyState::Partial, $convertedValue, $original, $currency);
        }
        if ($hasWithheld) {
            return new self(MoneyState::CompleteWithheld, $convertedValue, $original, $currency);
        }
        if ($convertedValue === null) {
            return new self(MoneyState::Absent, null, 0.0, null);
        }
        if ($convertedValue === 0.0) {
            return new self(MoneyState::Zero, 0.0, 0.0, null);
        }

        return new self(MoneyState::CompleteConverted, $convertedValue, 0.0, null);
    }

    /** Whether a single figure exists (false for partial/mixed/absent). */
    public function hasSingleTotal(): bool
    {
        return $this->state->isSingleTotal();
    }

    /** The single figure, or null when there is none to state. */
    public function amount(): ?float
    {
        return match ($this->state) {
            MoneyState::CompleteConverted, MoneyState::Zero => $this->converted,
            MoneyState::CompleteWithheld => $this->original,
            default => null,
        };
    }

    /** The currency `amount()` is in: reporting for converted/zero, the platform's for withheld, else null. */
    public function currency(?string $reporting): ?string
    {
        return match ($this->state) {
            MoneyState::CompleteConverted, MoneyState::Zero => $reporting,
            MoneyState::CompleteWithheld => $this->originalCurrency,
            default => null,
        };
    }
}
