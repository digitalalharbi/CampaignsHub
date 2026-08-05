<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;

/**
 * Snapchat Marketing API (`adsapi.snapchat.com/v1`).
 *
 * Two things about this API shape the code below. Ad accounts hang off an ORGANISATION, so without
 * `SNAPCHAT_ADS_ORGANIZATION_ID` there is nothing to list even with a perfectly good token — which is
 * why the organisation is in the platform's `requires`. And every money field is in micro-units of the
 * account currency, so a spend of 12.34 SAR arrives as `12340000`; dividing at the edge means the rest
 * of the system never has to know.
 *
 * Awaiting credentials on this install — no round trip has been made against a real organisation.
 */
final class SnapchatConnector extends ApiAdvertisingConnector
{
    /** Snapchat states money in millionths. */
    private const MICRO = 1_000_000;

    protected function platform(): string
    {
        return 'snapchat';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $organization = (string) $this->credentials()->get('organization_id');

        $body = $this->read(
            $this->api($tokens)->get($this->url("organizations/{$organization}/adaccounts")),
            'ad accounts',
        );

        $accounts = [];

        foreach ((array) ($body['adaccounts'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $a */
            $a = (array) ($wrapper['adaccount'] ?? []);

            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                'timezone' => isset($a['timezone']) ? (string) $a['timezone'] : null,
                'status' => strtolower((string) ($a['status'] ?? 'active')),
                'parent_external_id' => $organization,
                'raw' => $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adaccounts/{$adAccountId}/campaigns")),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) ($body['campaigns'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $c */
            $c = (array) ($wrapper['campaign'] ?? []);

            if (($c['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'status' => strtolower((string) ($c['status'] ?? 'unknown')),
                'objective' => isset($c['objective']) ? (string) $c['objective'] : null,
                'daily_budget' => isset($c['daily_budget_micro']) ? (float) $c['daily_budget_micro'] / self::MICRO : null,
                'lifetime_budget' => isset($c['lifetime_spend_cap_micro']) ? (float) $c['lifetime_spend_cap_micro'] / self::MICRO : null,
                'currency' => null, // stated on the account, not the campaign
                'raw' => $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adaccounts/{$adAccountId}/stats"), [
                'granularity' => 'DAY',
                'breakdown' => 'campaign',
                'fields' => 'spend,impressions,swipes,conversion_purchases,conversion_purchases_value',
                'start_time' => $from.'T00:00:00.000-00:00',
                'end_time' => $to.'T00:00:00.000-00:00',
            ]),
            'daily stats',
        );

        $rows = [];

        foreach ((array) ($body['timeseries_stats'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $series */
            $series = (array) ($wrapper['timeseries_stat'] ?? []);
            $campaignId = (string) ($series['id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            foreach ((array) ($series['timeseries'] ?? []) as $point) {
                /** @var array<string,mixed> $stats */
                $stats = (array) ($point['stats'] ?? []);

                $rows[] = array_filter([
                    'campaign_id' => $campaignId,
                    'date' => substr((string) ($point['start_time'] ?? $from), 0, 10),
                    'spend' => isset($stats['spend']) ? (float) $stats['spend'] / self::MICRO : null,
                    'impressions' => isset($stats['impressions']) ? (float) $stats['impressions'] : null,
                    // Snapchat's click is a swipe-up; mapping it to `clicks` is what makes a Snapchat
                    // CTR comparable to the other five on the same chart.
                    'clicks' => isset($stats['swipes']) ? (float) $stats['swipes'] : null,
                    'conversions' => isset($stats['conversion_purchases']) ? (float) $stats['conversion_purchases'] : null,
                    'revenue' => isset($stats['conversion_purchases_value'])
                        ? (float) $stats['conversion_purchases_value'] / self::MICRO
                        : null,
                ], static fn ($v) => $v !== null);
            }
        }

        return $rows;
    }
}
