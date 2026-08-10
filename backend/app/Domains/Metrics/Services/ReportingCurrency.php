<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Metrics\Models\MetricDefinition;
use App\Domains\Projects\Models\Project;
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
     * Advertising reporting is SAR unless a client says otherwise — the home market, and the currency
     * every dashboard, report and client link in this product is read in.
     */
    public const DEFAULT = 'SAR';

    /** @var array<string, string> project id → reporting currency, memoised per request */
    private array $projects = [];

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
    public function forProject(string $projectId): string
    {
        if (isset($this->projects[$projectId])) {
            return $this->projects[$projectId];
        }

        $currency = Project::withoutGlobalScopes()
            ->with('clientWorkspace:id,default_currency')
            ->find($projectId)?->clientWorkspace?->default_currency;

        return $this->projects[$projectId] = strtoupper((string) ($currency ?: self::DEFAULT));
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
