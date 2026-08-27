<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\MetricDefinition;
use Illuminate\Support\Carbon;

/**
 * FX-001 — one place that decides what a monetary figure means, and refuses to guess.
 *
 * ## The defect this closes
 *
 * `daily_metrics` has carried currency columns since C3.1 and nothing filled them. A USD ad account's
 * spend was stored as a bare number and summed into a SAR total, so an advertiser running $10,000
 * through one account and 10,000 SAR through another saw «20,000 SAR» — a figure that is not true in
 * any currency. Every screen was wrong identically, which is why nothing looked broken.
 *
 * ## Converted ONCE, at ingest
 *
 * `value` is the reporting-currency figure and every read path already sums exactly that column —
 * the dashboard, analytics, campaigns, the funnel, reports and the public report links all go
 * through `MetricsAggregator` or the same `SUM(value)`. Converting here rather than per screen is
 * what makes them agree by construction; a per-screen conversion would be six chances to differ, and
 * the public report has no session to resolve a rate against anyway.
 *
 * The original is never destroyed. `original_amount` and `original_currency` are written beside the
 * converted figure on every monetary row — including same-currency ones, where the honest record is
 * «SAR 500 at 1.0», not «no currency involved».
 *
 * ## Money only
 *
 * `metric_definitions.is_currency` decides. Multiplying impressions by 3.75 would be nonsense and the
 * kind of nonsense that survives review because the number still looks like a number.
 *
 * ## Fail-closed
 *
 * No rate → no converted figure. `value` is null, not zero and not the unconverted amount. See the
 * FX-001 migration for why those two alternatives are worse than an absence.
 */
final class ReportingCurrency
{
    /**
     * MONEY-USD-001 — USD is the reporting and comparison currency, not an assumption about sources.
     *
     * ## What this constant is, and is not
     *
     * It is the currency figures are COMPARED in when a client workspace has not stated its own. It
     * is not a claim about what any provider bills in. An ad account's own currency is provider
     * truth — USD, SAR, EUR, GBP or anything else it publishes — and is read from the account, never
     * inferred from the platform's name.
     *
     * ## Why USD rather than SAR
     *
     * A single comparison currency is what lets two accounts be added together at all. USD is the
     * one this product reports in, so an account already publishing USD converts at par — the
     * converter returns an `identity` rate for `from === to`, which is not a lookup and not a claim
     * about any publisher. An account in any other currency needs a real rate for the metric's date
     * and, without one, FX-001 still refuses to invent it: `value` is null, the money is withheld,
     * and the original amount and currency survive so the row converts itself the day a rate exists.
     *
     * That behaviour is unchanged. What changes is which conversions are needed, not which are
     * allowed.
     *
     * A workspace's own `default_currency` does NOT override this. An earlier revision let it, and
     * that put two aggregation bases in one column — see `forProject()` for why that cannot stand. A
     * workspace may choose how money is DISPLAYED; it may not choose what it is summed in.
     *
     * ## Not yet complete — MONEY-USD-002
     *
     * Rows already normalised with `project_currency = SAR` are NOT changed by this constant.
     * Re-normalising them from their preserved originals is `metrics:renormalise-currency`, which
     * must not double-convert and must not touch rows already in USD. Until that has been run and
     * verified across every surface, MONEY-USD is PARTIAL.
     */
    public const DEFAULT = 'USD';

    /** @var list<string>|null the monetary metric keys, read from the catalogue once */
    private ?array $monetary = null;

    public function __construct(private readonly CurrencyConverter $rates) {}

    /**
     * The currency this project's figures are reported in.
     *
     * From the CLIENT rather than the project: a client is who the report is for, and one client's
     * projects reported in different currencies would make their portfolio total unaddable. Falling
     * back to SAR rather than to null keeps every monetary row self-describing.
     */
    /**
     * The currency this project's money is NORMALISED and COMPARED in — always USD.
     *
     * A workspace's `default_currency` deliberately does NOT override this, and an earlier revision
     * of this method let it. That was wrong, and the reason is the whole point of having a canonical
     * currency: `value` is the column every read path sums — the dashboard, analytics, the funnel,
     * reports, the public links. If one workspace normalised into SAR and another into USD, two
     * projects could not be added together, a platform comparison would be summing different units,
     * and the same scope would answer differently depending on whose workspace asked. A per-workspace
     * basis is not a preference; it is a second truth.
     *
     * So the aggregation basis is fixed. What a workspace may still choose is how figures are
     * DISPLAYED, which is a presentation concern applied on the way out, over a single stored basis —
     * not a different basis per tenant.
     *
     * The original amount and original currency remain on every monetary row, so nothing about the
     * provider's own truth is lost by fixing the basis.
     */
    public function forProject(string $projectId): string
    {
        return self::DEFAULT;
    }

    /** Whether this metric key is money at all — the catalogue decides, not a hard-coded list. */
    public function isMonetary(string $metricKey): bool
    {
        $this->monetary ??= MetricDefinition::query()
            ->where('is_currency', true)
            ->pluck('key')
            ->map(fn ($k) => (string) $k)
            ->all();

        return in_array($metricKey, $this->monetary, true);
    }

    /**
     * Normalise one monetary figure into the reporting currency, or withhold it.
     *
     * The returned array is the currency half of a `daily_metrics` row. `value` is null exactly when
     * no trustworthy rate existed — the caller writes it as-is rather than substituting anything.
     *
     * @return array{
     *     value: ?float, original_amount: float, original_currency: string, project_currency: string,
     *     converted_amount: ?float, exchange_rate: ?float, rate_date: ?string, rate_source: ?string
     * }
     */
    public function normalise(float $amount, string $from, string $to, Carbon $date): array
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        $resolved = $this->rates->resolve($from, $to, $date);

        if ($resolved === null) {
            return [
                // Withheld. The original survives, so this row converts itself the day a rate exists.
                'value' => null,
                'original_amount' => $amount,
                'original_currency' => $from,
                'project_currency' => $to,
                'converted_amount' => null,
                'exchange_rate' => null,
                'rate_date' => null,
                'rate_source' => null,
            ];
        }

        $converted = $amount * $resolved['rate'];

        return [
            'value' => $converted,
            'original_amount' => $amount,
            'original_currency' => $from,
            'project_currency' => $to,
            'converted_amount' => $converted,
            'exchange_rate' => $resolved['rate'],
            'rate_date' => $resolved['rate_date'],
            'rate_source' => $resolved['source'],
        ];
    }
}
