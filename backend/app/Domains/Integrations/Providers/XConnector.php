<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use Illuminate\Support\Carbon;

/**
 * X (Twitter) Ads API (`ads-api.x.com/12`).
 *
 * Its stats endpoint is the strictest of the six: it will not report on an account, only on named
 * entities, at most 20 at a time, and it returns metrics as ARRAYS indexed by day rather than one row
 * per day. So campaigns are fetched first, then their ids are chunked, and each chunk's day-indexed
 * arrays are unrolled back into ordinary daily rows.
 *
 * Money is in micros of the account currency, as with Snapchat and Google.
 *
 * Awaiting credentials — and X Ads access is a separate approval tier that a normal developer account
 * does not carry.
 */
final class XConnector extends ApiAdvertisingConnector
{
    private const MICRO = 1_000_000;

    /** The documented ceiling for `entity_ids` on a single stats call. */
    private const ENTITIES_PER_CALL = 20;

    protected function platform(): string
    {
        return 'x';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read($this->api($tokens)->get($this->url('accounts')), 'ad accounts');

        $accounts = [];

        foreach ((array) ($body['data'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => null,
                'timezone' => isset($a['timezone']) ? (string) $a['timezone'] : null,
                'status' => ($a['deleted'] ?? false) === true ? 'inactive' : 'active',
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("accounts/{$adAccountId}/campaigns"), ['count' => 200]),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) ($body['data'] ?? []) as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'status' => strtolower((string) ($c['entity_status'] ?? 'unknown')),
                'objective' => isset($c['objective']) ? (string) $c['objective'] : null,
                'daily_budget' => isset($c['daily_budget_amount_local_micro'])
                    ? (float) $c['daily_budget_amount_local_micro'] / self::MICRO
                    : null,
                'lifetime_budget' => isset($c['total_budget_amount_local_micro'])
                    ? (float) $c['total_budget_amount_local_micro'] / self::MICRO
                    : null,
                'currency' => isset($c['currency']) ? (string) $c['currency'] : null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $campaignIds = array_map(
            static fn (array $c): string => $c['external_id'],
            $this->fetchCampaigns($tokens, $adAccountId),
        );

        if ($campaignIds === []) {
            return []; // nothing to ask about; the account has no campaigns in this window
        }

        $rows = [];

        foreach (array_chunk($campaignIds, self::ENTITIES_PER_CALL) as $chunk) {
            $body = $this->read(
                $this->api($tokens)->get($this->url("stats/accounts/{$adAccountId}"), [
                    'entity' => 'CAMPAIGN',
                    'entity_ids' => implode(',', $chunk),
                    'granularity' => 'DAY',
                    'metric_groups' => 'BILLING,ENGAGEMENT',
                    'placement' => 'ALL_ON_TWITTER',
                    'start_time' => $from.'T00:00:00Z',
                    'end_time' => $to.'T00:00:00Z',
                ]),
                'daily stats',
            );

            foreach ((array) ($body['data'] ?? []) as $entity) {
                $campaignId = (string) ($entity['id'] ?? '');
                /** @var array<string,mixed> $series */
                $series = (array) (($entity['id_data'][0]['metrics'] ?? []));

                if ($campaignId === '' || $series === []) {
                    continue;
                }

                foreach ($this->unroll($series, $from) as $row) {
                    $rows[] = ['campaign_id' => $campaignId, ...$row];
                }
            }
        }

        return $rows;
    }

    /**
     * Turn day-indexed metric arrays into one row per day.
     *
     * X answers `{"billed_charge_local_micro":[1000000,2000000], "impressions":[10,20]}` — position 0
     * is the first day of the window. A null at a position means "no data that day", which is not the
     * same as zero and is left out rather than written as one.
     *
     * @param  array<string,mixed>  $series
     * @return list<array<string,mixed>>
     */
    private function unroll(array $series, string $from): array
    {
        $start = Carbon::parse($from);

        /** @var array<int,float|null> $spend */
        $spend = (array) ($series['billed_charge_local_micro'] ?? []);
        $length = max(
            count($spend),
            count((array) ($series['impressions'] ?? [])),
            count((array) ($series['clicks'] ?? [])),
        );

        $rows = [];

        for ($day = 0; $day < $length; $day++) {
            $row = array_filter([
                'date' => $start->copy()->addDays($day)->toDateString(),
                'spend' => isset($spend[$day]) ? (float) $spend[$day] / self::MICRO : null,
                'impressions' => $this->at($series, 'impressions', $day),
                'clicks' => $this->at($series, 'clicks', $day),
                'conversions' => $this->at($series, 'conversion_purchases', $day),
                'video_views' => $this->at($series, 'video_total_views', $day),
            ], static fn ($v) => $v !== null);

            // A day with nothing but its own date is not a measurement.
            if (count($row) > 1) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @param array<string,mixed> $series */
    private function at(array $series, string $metric, int $day): ?float
    {
        $values = $series[$metric] ?? null;

        if (! is_array($values) || ! isset($values[$day]) || $values[$day] === null) {
            return null;
        }

        return (float) $values[$day];
    }
}
