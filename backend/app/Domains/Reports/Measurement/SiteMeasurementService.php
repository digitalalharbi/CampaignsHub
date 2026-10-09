<?php

declare(strict_types=1);

namespace App\Domains\Reports\Measurement;

use App\Domains\Integrations\Measurement\Ga4Metrics;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * GA4-INTEGRATION-001 — what the client's OWN SITE measured, for the client's own report.
 *
 * ## It is a separate section because it answers a different question
 *
 * An ad platform reports what IT believes its ads caused. GA4 reports what happened on the client's
 * site, attributed by GA4's own model, in the property's own calendar. The two are not addable — and
 * the owner's instruction is explicit: «Do not calculate a blended ROAS from incompatible attribution
 * sources.»
 *
 * So this service never touches the ad figures, never divides one by the other, and the section it
 * feeds is labelled as site measurement rather than as a result of the campaigns. A reader shown two
 * revenue numbers on one page will add them unless the page says not to.
 *
 * ## A rate is not a sum
 *
 * `engagement_rate` arrives as a fraction of sessions. Adding a week of fractions produces a number
 * above 1 that reads as a percentage, which is how a 61% engagement rate becomes 427%. Rates are
 * averaged weighted by the sessions of their own day, and a day without sessions contributes nothing
 * rather than contributing a zero.
 *
 * ## Absent is reported as absent
 *
 * A property with no ecommerce configured reports no revenue, and the sync writes no row. The totals
 * therefore carry `null` for it and the key is named in `absent`, so the section can say «لا تقيس
 * هذه الخاصية الإيرادات» instead of «‏0.00 ر.س» — which would tell a client their site earned
 * nothing.
 */
final class SiteMeasurementService
{
    /**
     * The measured period for the property SELECTED for this project, or null when there is none.
     *
     * Null rather than an empty shape, so the section is absent rather than present-and-empty. A
     * project with no Analytics property is the ordinary case and is not a gap to be filled.
     *
     * @return array{
     *     property: array{id: string, name: string, timezone: ?string},
     *     currency: ?string,
     *     from: string,
     *     to: string,
     *     totals: array<string, float|null>,
     *     rates: array<string, float|null>,
     *     series: list<array<string, float|string|null>>,
     *     absent: list<string>,
     *     days: int
     * }|null
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): ?array
    {
        if ($projectId === '') {
            return null;
        }

        $property = $this->selectedProperty($tenantId, $projectId);

        if ($property === null) {
            return null;
        }

        /** @var array<int, object{metric_date: string, metric_key: string, value: ?string, currency: ?string}> $rows */
        $rows = DB::table('measurement_daily_metrics')
            /*
             * Both predicates written out, because `DB::table()` carries no global scope: the tenant
             * is not implied here the way it is on a model. And the property, because a project that
             * once had a different property bound still holds that property's rows — history is kept
             * when a binding is detached, and summing both would double a day.
             */
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('external_account_id', $property->getKey())
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('metric_date')
            ->get(['metric_date', 'metric_key', 'value', 'currency'])
            ->all();

        if ($rows === []) {
            /*
             * A selected property with no rows in the window. Null, because the honest statement is
             * «this period has not been read», and a section of nulls would read as a measured zero.
             */
            return null;
        }

        $byDay = [];
        $currency = null;

        foreach ($rows as $row) {
            $date = (string) $row->metric_date;
            /* Postgres returns a date as `Y-m-d 00:00:00` on some drivers; the day is the first ten. */
            $date = substr($date, 0, 10);
            $byDay[$date][(string) $row->metric_key] = (float) ($row->value ?? 0);

            if ($row->currency !== null && $currency === null) {
                $currency = (string) $row->currency;
            }
        }

        ksort($byDay);

        return [
            'property' => [
                /*
                 * The GA4 property id and its name — never the internal `external_accounts` uuid.
                 * «Do not expose asset ids or private storage paths»; a primary key on a client's
                 * report is the same mistake in a different column.
                 */
                'id' => (string) $property->external_id,
                'name' => (string) $property->name,
                'timezone' => $property->timezone,
            ],
            'currency' => $currency,
            'from' => (string) array_key_first($byDay),
            'to' => (string) array_key_last($byDay),
            'totals' => $this->totals($byDay),
            'rates' => $this->rates($byDay),
            'series' => $this->series($byDay),
            'absent' => $this->absent($byDay),
            'days' => count($byDay),
        ];
    }

    /**
     * The one property this project reads — the active `analytics` binding.
     *
     * A project has at most one: the binding enforces one active assignment per account, and nothing
     * in this product asks for two Analytics properties in one project. The first by binding age is
     * taken if an older estate somehow holds two, rather than summing them: two properties measure
     * two different sites, and adding them would invent a site that does not exist.
     */
    private function selectedProperty(string $tenantId, string $projectId): ?ExternalAccount
    {
        $accountId = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('is_active', true)
            ->where('provider', 'ga4')
            ->orderBy('created_at')
            ->value('external_account_id');

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        return ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)
            ->find($accountId);
    }

    /**
     * The counts, summed. Rates are excluded here and handled separately.
     *
     * @param  array<string, array<string, float>>  $byDay
     * @return array<string, float|null>
     */
    private function totals(array $byDay): array
    {
        $out = [];

        foreach (Ga4Metrics::MAP as $key) {
            if (Ga4Metrics::isRate($key)) {
                continue;
            }

            $days = array_filter(
                array_map(static fn (array $day): ?float => $day[$key] ?? null, $byDay),
                static fn (?float $v): bool => $v !== null,
            );

            /* Absent stays absent: no day reported it, so there is no total, not a zero. */
            $out[$key] = $days === [] ? null : array_sum($days);
        }

        return $out;
    }

    /**
     * The rates, averaged weighted by each day's sessions.
     *
     * @param  array<string, array<string, float>>  $byDay
     * @return array<string, float|null>
     */
    private function rates(array $byDay): array
    {
        $out = [];

        foreach (Ga4Metrics::RATES as $key) {
            $weighted = 0.0;
            $weight = 0.0;

            foreach ($byDay as $day) {
                $rate = $day[$key] ?? null;
                $sessions = $day['sessions'] ?? null;

                if ($rate === null || $sessions === null || $sessions <= 0) {
                    continue;
                }

                $weighted += $rate * $sessions;
                $weight += $sessions;
            }

            $out[$key] = $weight > 0 ? $weighted / $weight : null;
        }

        return $out;
    }

    /**
     * One row per measured day, for the chart.
     *
     * @param  array<string, array<string, float>>  $byDay
     * @return list<array<string, float|string|null>>
     */
    private function series(array $byDay): array
    {
        $out = [];

        foreach ($byDay as $date => $day) {
            $row = ['date' => $date];

            foreach (Ga4Metrics::MAP as $key) {
                $row[$key] = $day[$key] ?? null;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * The keys this property does not measure at all — named, so the section can say so.
     *
     * @param  array<string, array<string, float>>  $byDay
     * @return list<string>
     */
    private function absent(array $byDay): array
    {
        $out = [];

        foreach (Ga4Metrics::MAP as $key) {
            $reported = array_filter($byDay, static fn (array $day): bool => isset($day[$key]));

            if ($reported === []) {
                $out[] = $key;
            }
        }

        /* Already a list — it is built by `[] =`, and PHPStan is right that wrapping it does nothing. */
        return $out;
    }
}
