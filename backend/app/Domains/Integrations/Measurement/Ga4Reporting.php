<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;

/**
 * GA4-INTEGRATION-001 — reading a property: its settings, and its days.
 *
 * Two calls to two different Google hosts, which is why `api_base` in the config cannot stand for
 * both and each is named where it is used:
 *
 *   - the ADMIN API answers what the property IS — its timezone and its currency;
 *   - the DATA API answers what happened on it, one row per day.
 *
 * ## The day belongs to the property, not to us
 *
 * GA4's date boundary follows the property's configured timezone. A property set to `Asia/Riyadh`
 * closes 2026-10-08 three hours before UTC does, so «yesterday» computed on the server is a different
 * day from the one Google will report — and the overlap shows up as a day that disagrees with the
 * client's own Analytics screen. So the window is computed in the PROPERTY's timezone, and the
 * timezone is recorded with every figure.
 *
 * ## Why the window is deliberately re-asked
 *
 * GA4 restates a day for up to 48 hours as late events arrive. A sync that only ever fetched
 * yesterday would freeze each day at its first, incomplete value. The caller therefore asks for an
 * overlapping window and the writer upserts — which is also why the unique key on
 * `measurement_daily_metrics` exists.
 */
final class Ga4Reporting
{
    private const ADMIN_API = 'https://analyticsadmin.googleapis.com/v1beta';

    private const DATA_API = 'https://analyticsdata.googleapis.com/v1beta';

    /** GA4's own cap on a single `runReport` response. */
    private const ROW_LIMIT = 100000;

    public function __construct(private readonly TokenVault $vault) {}

    /**
     * What the property IS — the two settings every figure read from it depends on.
     *
     * Fetched for a SELECTED property only. An agency identity reaching fifty properties would spend
     * fifty calls to learn settings for the one it bound, so discovery does not ask and this does.
     *
     * @return array{timezone: string, currency: ?string, display_name: string}
     */
    public function settings(ProviderConnection $connection, string $propertyId): array
    {
        $tokens = $this->vault->fresh($connection);

        $response = PlatformHttp::client('ga4')
            ->withToken($tokens->accessToken)
            ->get(self::ADMIN_API.'/properties/'.$propertyId);

        if ($response->failed()) {
            throw new Ga4ReportFailed(PlatformHttp::reason($response));
        }

        $timezone = (string) $response->json('timeZone', '');

        if ($timezone === '') {
            /*
             * Refused rather than defaulted to UTC.
             *
             * Every property has a timezone, so an absent one means we are not reading the property
             * we think we are. Guessing UTC would silently shift a Gulf client's whole report by a
             * day — the kind of wrongness that is never noticed and never forgiven.
             */
            throw new Ga4ReportFailed('The property did not state a timezone.');
        }

        return [
            'timezone' => $timezone,
            /* Null where the property has no ecommerce configured — absent, not 'USD'. */
            'currency' => ($c = (string) $response->json('currencyCode', '')) === '' ? null : $c,
            'display_name' => (string) $response->json('displayName', ''),
        ];
    }

    /**
     * One row per day in the window, in the property's own calendar.
     *
     * @return list<array{date: string, values: array<string, float>}>
     */
    public function dailyRows(ProviderConnection $connection, string $propertyId, Carbon $from, Carbon $to): array
    {
        $tokens = $this->vault->fresh($connection);

        $response = PlatformHttp::client('ga4')
            ->withToken($tokens->accessToken)
            ->post(self::DATA_API.'/properties/'.$propertyId.':runReport', [
                'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
                'dimensions' => [['name' => 'date']],
                'metrics' => array_map(static fn (string $m): array => ['name' => $m], Ga4Metrics::requested()),
                'limit' => self::ROW_LIMIT,
                /*
                 * Ask for the sampling and quota state rather than assume there is none. A sampled
                 * report is an ESTIMATE, and a figure the product cannot tell apart from a counted
                 * one would be presented to a client as measured fact.
                 */
                'returnPropertyQuota' => true,
            ]);

        if ($response->failed()) {
            throw new Ga4ReportFailed(PlatformHttp::reason($response));
        }

        /*
         * The headers name the metrics in the ORDER Google returned them, which is not promised to be
         * the order they were requested in. Reading `metricValues[3]` as revenue because revenue was
         * fourth in the request is a bug that produces plausible numbers in the wrong rows.
         */
        $headers = array_map(
            static fn (array $h): ?string => Ga4Metrics::keyFor((string) ($h['name'] ?? '')),
            $response->json('metricHeaders') ?? [],
        );

        $out = [];

        foreach ($response->json('rows') ?? [] as $row) {
            $date = (string) ($row['dimensionValues'][0]['value'] ?? '');

            if ($date === '') {
                continue;
            }

            $values = [];

            foreach ($row['metricValues'] ?? [] as $i => $cell) {
                $key = $headers[$i] ?? null;

                if ($key === null) {
                    continue; // a metric we did not ask for, or no longer recognise
                }

                $raw = $cell['value'] ?? null;

                if ($raw === null || $raw === '') {
                    continue; // absent stays absent — never written down as a zero
                }

                $values[$key] = (float) $raw;
            }

            $out[] = ['date' => $this->isoDate($date), 'values' => $values];
        }

        return $out;
    }

    /** GA4's `date` dimension is `YYYYMMDD`; everything downstream speaks `YYYY-MM-DD`. */
    private function isoDate(string $compact): string
    {
        return preg_match('/^\d{8}$/', $compact) === 1
            ? substr($compact, 0, 4).'-'.substr($compact, 4, 2).'-'.substr($compact, 6, 2)
            : $compact;
    }

    /**
     * GA4-ANALYTICS-PRODUCT-001 — one breakdown, per day, as the property reports it.
     *
     * @return list<array{date: string, d1: string, d2: string, values: array<string, float>}>
     */
    public function breakdownRows(ProviderConnection $connection, string $propertyId, Carbon $from, Carbon $to, string $breakdown): array
    {
        $dimensions = Ga4Breakdowns::DIMENSIONS[$breakdown] ?? null;
        if ($dimensions === null) {
            return [];
        }
        $metrics = Ga4Breakdowns::metricsFor($breakdown);

        $tokens = $this->vault->fresh($connection);
        $response = PlatformHttp::client('ga4')
            ->withToken($tokens->accessToken)
            ->post(self::DATA_API.'/properties/'.$propertyId.':runReport', [
                'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
                'dimensions' => array_map(static fn (string $d): array => ['name' => $d], ['date', ...$dimensions]),
                'metrics' => array_map(static fn (string $m): array => ['name' => $m], array_keys($metrics)),
                'limit' => self::ROW_LIMIT,
            ]);

        if ($response->failed()) {
            throw new Ga4ReportFailed(PlatformHttp::reason($response));
        }

        $headers = array_map(static fn (array $h): ?string => $metrics[(string) ($h['name'] ?? '')] ?? null, $response->json('metricHeaders') ?? []);
        $out = [];
        foreach ($response->json('rows') ?? [] as $row) {
            $dims = array_map(static fn (array $d): string => (string) ($d['value'] ?? ''), $row['dimensionValues'] ?? []);
            $date = $dims[0] ?? '';
            if ($date === '') {
                continue;
            }
            $values = [];
            foreach ($row['metricValues'] ?? [] as $i => $cell) {
                $key = $headers[$i] ?? null;
                if ($key !== null && isset($cell['value']) && is_numeric($cell['value'])) {
                    $values[$key] = (float) $cell['value'];
                }
            }
            $out[] = [
                'date' => substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2),
                'd1' => mb_substr($dims[1] ?? '', 0, 512),
                'd2' => mb_substr($dims[2] ?? '', 0, 512),
                'values' => $values,
            ];
        }

        return $out;
    }
}
