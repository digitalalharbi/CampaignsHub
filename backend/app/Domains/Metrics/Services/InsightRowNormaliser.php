<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\DTO\NormalizedMetric;
use Illuminate\Support\Carbon;

/**
 * FX-001 — turning a connector's insight rows into normalized metrics, currency and all.
 *
 * ## Why this is its own class
 *
 * `AccountMetricsSyncer` does two unrelated jobs: it orchestrates a connector (bind the connection,
 * refuse without credentials, record a run, retain the raw payload) and it maps rows. Only the second
 * has anything to do with money, and it was the one nobody could test in isolation —
 * `AdvertisingConnectorRegistry` is `final`, so a test wanting to feed the mapper three rows had to
 * stand up an entire connector first, which is why the currency gap survived so long behind tests
 * that all looked green.
 *
 * The syncer still owns the pipeline; this owns what a row MEANS.
 */
final class InsightRowNormaliser
{
    public function __construct(private readonly ReportingCurrency $currency) {}

    /**
     * @param  array<int,array<string,mixed>>  $records
     * @param  list<string>  $carriedKeys  every metric key this pipeline stores
     * @return array{0: list<NormalizedMetric>, 1: int} [metrics, records that matched no campaign]
     */
    public function normalise(ExternalAccount $account, array $records, array $carriedKeys): array
    {
        $metrics = [];
        $skipped = 0;

        // Provider campaign id → the external campaign row we already know about.
        $known = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)
            ->get(['id', 'external_id', 'project_id', 'unified_campaign_id'])
            ->keyBy('external_id');

        foreach ($records as $row) {
            $link = $known->get((string) ($row['campaign_id'] ?? ''));

            if ($link === null) {
                // An insight for a campaign we have never discovered is not silently dropped — it is
                // counted so the run can report itself as partial.
                $skipped++;

                continue;
            }

            $date = Carbon::parse((string) ($row['date'] ?? Carbon::now()->toDateString()));

            /*
             * The currency this row's money is actually IN.
             *
             * Per row first, because a platform that labels each insight is the authority on its own
             * figures; the ad account's own currency is the fallback, and it is what every platform
             * this product speaks to publishes at account level. Only if BOTH are missing does the
             * reporting currency stand in — treating an unlabelled figure as already converted, which
             * is what the old code did unconditionally and without saying so.
             */
            $reporting = $this->currency->forProject((string) $link->project_id);
            $source = strtoupper((string) ($row['currency'] ?? $account->currency ?? $reporting));

            /*
             * META-ATTRIB-001 — the window this row's figures were measured over.
             *
             * Read from the row for the same reason the currency is: the connector is the only thing
             * that knows what it asked the provider for. Nothing passed this before, so every metric
             * fell through to `NormalizedMetric`'s constructor default and the column held the single
             * literal `default` for every row of every provider — which is exactly why a grouping on
             * it could only ever return one bucket, and why the mixed-window warning built on top of
             * that grouping was incapable of firing.
             *
             * A connector that says nothing still gets `default`, and that remains the truthful word:
             * «this provider's own unstated default», not «the same window as everyone else».
             */
            $window = $this->window($row);

            foreach ($carriedKeys as $key) {
                if (! array_key_exists($key, $row)) {
                    continue;
                }

                $amount = (float) $row[$key];

                /*
                 * Money is converted; counts are not.
                 *
                 * `metric_definitions.is_currency` decides, so a metric catalogued as money later is
                 * normalised without anybody editing this loop. Applying a rate to impressions would
                 * be nonsense that still looks like a number — precisely the kind that survives review.
                 */
                if (! $this->currency->isMonetary($key)) {
                    $metrics[] = $this->metric($account, $link, $key, $date, $amount, $window);

                    continue;
                }

                $money = $this->currency->normalise($amount, $source, $reporting, $date);

                $metrics[] = $this->metric(
                    $account, $link, $key, $date,
                    // Null when no rate could be vouched for. NOT zero, and not the unconverted
                    // figure — see the FX-001 migration for why both alternatives are worse.
                    $money['value'],
                    $window,
                    $money,
                );
            }
        }

        return [$metrics, $skipped];
    }

    /**
     * The window named by the row, or `default` when the connector named none.
     *
     * Trimmed and length-capped because it is stored in a `varchar` and grouped on: a provider that
     * one day answers with something long or padded would otherwise split one window into two
     * buckets and report a mixture that is not there.
     *
     * @param  array<string,mixed>  $row
     */
    private function window(array $row): string
    {
        $window = $row['attribution_window'] ?? null;

        if (! is_string($window) || trim($window) === '') {
            return 'default';
        }

        return mb_substr(trim($window), 0, 60);
    }

    /** @param  array<string,mixed>|null  $money */
    private function metric(
        ExternalAccount $account,
        ExternalCampaign $link,
        string $key,
        Carbon $date,
        ?float $value,
        string $window,
        ?array $money = null,
    ): NormalizedMetric {
        return new NormalizedMetric(
            tenantId: $account->tenant_id,
            projectId: $link->project_id,
            externalAccountId: $account->id,
            externalCampaignId: $link->id,
            provider: $account->provider,
            metricKey: $key,
            metricDate: $date,
            value: $value,
            unifiedCampaignId: $link->unified_campaign_id,
            originalCurrency: $money['original_currency'] ?? null,
            projectCurrency: $money['project_currency'] ?? null,
            originalAmount: $money['original_amount'] ?? null,
            convertedAmount: $money['converted_amount'] ?? null,
            exchangeRate: $money['exchange_rate'] ?? null,
            rateDate: $money['rate_date'] ?? null,
            rateSource: $money['rate_source'] ?? null,
            attributionWindow: $window,
        );
    }
}
