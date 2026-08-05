<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;

/**
 * TikTok Marketing API (`business-api.tiktok.com/open_api/v1.3`).
 *
 * The API that agrees with nobody: authentication is an `Access-Token` header rather than a bearer,
 * list parameters are JSON-encoded strings inside the query, and — the one that actually bites — a
 * failure arrives as **HTTP 200 with a non-zero `code`**. `PlatformHttp::succeeded()` is what keeps
 * that from being stored as data, and `ApiAdvertisingConnector::read()` routes every call through it.
 *
 * Awaiting credentials on this install.
 */
final class TikTokConnector extends ApiAdvertisingConnector
{
    protected function platform(): string
    {
        return 'tiktok';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        // The advertiser list is the one call that still wants the app credentials in the query.
        $body = $this->read(
            $this->api($tokens)->get($this->url('oauth2/advertiser/get/'), [
                'app_id' => $this->credentials()->get('client_id'),
                'secret' => $this->credentials()->get('client_secret'),
            ]),
            'advertiser accounts',
        );

        $accounts = [];

        foreach ((array) (($body['data']['list'] ?? [])) as $a) {
            if (($a['advertiser_id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $a['advertiser_id'],
                'name' => (string) ($a['advertiser_name'] ?? $a['advertiser_id']),
                'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                'timezone' => isset($a['timezone']) ? (string) $a['timezone'] : null,
                'status' => 'active',
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('campaign/get/'), [
                'advertiser_id' => $adAccountId,
                'page_size' => 100,
            ]),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) (($body['data']['list'] ?? [])) as $c) {
            if (($c['campaign_id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['campaign_id'],
                'name' => (string) ($c['campaign_name'] ?? $c['campaign_id']),
                'status' => strtolower((string) ($c['operation_status'] ?? 'unknown')),
                'objective' => isset($c['objective_type']) ? (string) $c['objective_type'] : null,
                'daily_budget' => ($c['budget_mode'] ?? null) === 'BUDGET_MODE_DAY' && isset($c['budget'])
                    ? (float) $c['budget']
                    : null,
                'lifetime_budget' => ($c['budget_mode'] ?? null) === 'BUDGET_MODE_TOTAL' && isset($c['budget'])
                    ? (float) $c['budget']
                    : null,
                'currency' => null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('report/integrated/get/'), [
                'advertiser_id' => $adAccountId,
                'report_type' => 'BASIC',
                'data_level' => 'AUCTION_CAMPAIGN',
                // TikTok wants these as JSON strings inside the query, not repeated parameters.
                'dimensions' => json_encode(['campaign_id', 'stat_time_day'], JSON_THROW_ON_ERROR),
                'metrics' => json_encode(
                    ['spend', 'impressions', 'clicks', 'conversion', 'total_purchase_value', 'reach', 'video_play_actions'],
                    JSON_THROW_ON_ERROR,
                ),
                'start_date' => $from,
                'end_date' => $to,
                'page_size' => 1000,
            ]),
            'daily report',
        );

        $rows = [];

        foreach ((array) (($body['data']['list'] ?? [])) as $row) {
            /** @var array<string,mixed> $dims */
            $dims = (array) ($row['dimensions'] ?? []);
            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);

            $campaignId = (string) ($dims['campaign_id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $rows[] = array_filter([
                'campaign_id' => $campaignId,
                // `stat_time_day` is «2026-08-05 00:00:00»; the pipeline stores a date.
                'date' => substr((string) ($dims['stat_time_day'] ?? $from), 0, 10),
                'spend' => isset($metrics['spend']) ? (float) $metrics['spend'] : null,
                'impressions' => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
                'clicks' => isset($metrics['clicks']) ? (float) $metrics['clicks'] : null,
                'conversions' => isset($metrics['conversion']) ? (float) $metrics['conversion'] : null,
                'revenue' => isset($metrics['total_purchase_value']) ? (float) $metrics['total_purchase_value'] : null,
                'reach' => isset($metrics['reach']) ? (float) $metrics['reach'] : null,
                'video_views' => isset($metrics['video_play_actions']) ? (float) $metrics['video_play_actions'] : null,
            ], static fn ($v) => $v !== null);
        }

        return $rows;
    }
}
