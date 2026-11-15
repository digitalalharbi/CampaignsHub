<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Fx;

/**
 * FX-ENGINE-001 — the result of {@see FxEngine::convert()}: the original, the converted figure and everything needed to
 * audit it. This array shape IS the money serializer's contract (the TS twin is `lib/money/fx.ts` `FxConversion`).
 *
 * WITHHELD means there was no valid rate for the amount's date: `converted_amount` is NULL — never zero, never the raw
 * number — and the reader is sent to the rates page with «بانتظار سعر صرف (EUR)».
 */
final readonly class FxConversion
{
    public const RATES_PAGE = '/admin/settings/currency-rates';

    /**
     * @param  list<array<string, mixed>>  $legs  for a cross through USD: the two rates that made it
     */
    public function __construct(
        public MoneyValue $original,
        public string $target,
        public ?float $converted,
        public ?float $rate,
        public ?string $rateDate,
        public ?string $source,
        public ?string $kind,
        public ?string $recordedBy,
        public bool $stale,
        public bool $withheld,
        public array $legs = [],
        public bool $expired = false,
    ) {}

    /**
     * PROJECT-LIFECYCLE-001 §7 (N4) — «سعر قديم — بتاريخ 2026-11-09»: the one wording for a figure converted at a rate older
     * than the stale window or past its validity (an expired manual rate is used, never left to withhold the spend).
     */
    public function staleLabel(bool $ar = true): ?string
    {
        if (! $this->stale || $this->withheld) {
            return null;
        }

        return $ar ? "سعر قديم — بتاريخ {$this->rateDate}" : "Old rate — dated {$this->rateDate}";
    }

    /** «بانتظار سعر صرف (EUR)» — the one wording for a withheld figure. */
    public function pendingLabel(bool $ar = true): ?string
    {
        if (! $this->withheld) {
            return null;
        }

        return $ar ? "بانتظار سعر صرف ({$this->original->currency})" : "Awaiting exchange rate ({$this->original->currency})";
    }

    /**
     * @return array{
     *   original_amount: float, original_currency: string, date: string, currency: string,
     *   converted_amount: ?float, rate: ?float, rate_date: ?string, source: ?string, kind: ?string,
     *   recorded_by: ?string, stale: bool, withheld: bool, pending_label: ?string, rates_page: ?string,
     *   legs: list<array<string, mixed>>, via: string, expired: bool, stale_label: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'original_amount' => $this->original->amount,
            'original_currency' => $this->original->currency,
            'date' => $this->original->date,
            'currency' => $this->target,
            'converted_amount' => $this->converted === null ? null : round($this->converted, 6),
            'rate' => $this->rate,
            'rate_date' => $this->rateDate,
            'source' => $this->source,
            'kind' => $this->kind,
            'recorded_by' => $this->recordedBy,
            'stale' => $this->stale,
            'withheld' => $this->withheld,
            'pending_label' => $this->pendingLabel(),
            'rates_page' => $this->withheld ? self::RATES_PAGE : null,
            'legs' => $this->legs,
            // The serializer's guard stamp: a money payload without it did not pass through convert().
            'via' => 'fx.convert',
            'expired' => $this->expired,
            'stale_label' => $this->staleLabel(),
        ];
    }
}
