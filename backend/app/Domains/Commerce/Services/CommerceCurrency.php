<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\ValueObjects\MoneyConversion;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Metrics\Services\SourceCurrency;
use Illuminate\Support\Carbon;

/**
 * COMMERCE-FX-001 — what currency a store row's money is in, and what it becomes.
 *
 * ## Why it borrows from Metrics rather than owning a second answer
 *
 * A project has ONE reporting currency and ONE set of dated rates. Ad spend and store revenue land on
 * the same chart and are divided into each other to make ROAS, so a commerce-side copy of either
 * answer would be a second definition of the same fact — and the first month the two disagreed, a
 * ROAS would be wrong in a way no reader could decompose. {@see ReportingCurrency} decides the
 * currency, {@see CurrencyConverter} resolves the dated rate, and this only knows how to ask for one
 * rate per row instead of one per amount.
 *
 * ## The date is the row's own moment
 *
 * An order is converted at the rate for the day it was PLACED, not the day the sweep ran. Re-syncing
 * a ninety-day window must not restate January's revenue at August's rate; the whole reason
 * `currency_rates` is keyed by date is so a historical figure stays what it was.
 */
final class CommerceCurrency
{
    public function __construct(
        private readonly ReportingCurrency $reporting,
        private readonly CurrencyConverter $rates,
    ) {}

    /**
     * The conversion that applies to one store row.
     *
     * The source currency falls back the way the ad pipeline's does: the row's own label, then the
     * store account's — and then nothing.
     *
     * MONEY-CURRENCY-NEVER-ASSUMED-001 — the third step used to be the reporting currency: an
     * unlabelled order was treated as already converted and recorded as such. That is the assumption
     * that stored riyal ad spend as dollars. A row whose currency nobody stated now has no rate, so
     * its amounts are WITHHELD (`total` null, `original_total` kept, `original_currency` null) through
     * the same path a missing rate takes, and the funnel already counts it as withheld.
     */
    public function for(string $projectId, ?string $sourceCurrency, ?Carbon $on): MoneyConversion
    {
        $to = $this->reporting->forProject($projectId);
        $from = SourceCurrency::clean($sourceCurrency);

        $date = $on ?? Carbon::now();
        $resolved = $from === null ? null : $this->rates->resolve($from, $to, $date);

        return new MoneyConversion(
            originalCurrency: $from,
            reportingCurrency: $to,
            rate: $resolved['rate'] ?? null,
            rateDate: $resolved['rate_date'] ?? null,
            rateSource: $resolved['source'] ?? null,
        );
    }

    /** The currency this project's store figures are reported in. */
    public function reportingCurrencyFor(string $projectId): string
    {
        return $this->reporting->forProject($projectId);
    }
}
