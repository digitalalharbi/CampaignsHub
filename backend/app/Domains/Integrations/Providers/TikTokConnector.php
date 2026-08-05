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

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('adgroup/get/'), [
                'advertiser_id' => $adAccountId,
                'page_size' => 100,
            ]),
            'ad groups',
        );

        $sets = [];

        foreach ((array) (($body['data']['list'] ?? [])) as $g) {
            if (($g['adgroup_id'] ?? null) === null || ($g['campaign_id'] ?? null) === null) {
                continue;
            }

            $budget = isset($g['budget']) ? (float) $g['budget'] : null;
            $mode = (string) ($g['budget_mode'] ?? '');

            $sets[] = [
                'external_id' => (string) $g['adgroup_id'],
                'campaign_external_id' => (string) $g['campaign_id'],
                'name' => (string) ($g['adgroup_name'] ?? $g['adgroup_id']),
                'status' => strtolower((string) ($g['operation_status'] ?? 'unknown')),
                'optimization_goal' => isset($g['optimization_goal']) ? strtolower((string) $g['optimization_goal']) : null,
                'bid_strategy' => isset($g['bid_type']) ? strtolower((string) $g['bid_type']) : null,
                'daily_budget' => $mode === 'BUDGET_MODE_DAY' ? $budget : null,
                'lifetime_budget' => $mode === 'BUDGET_MODE_TOTAL' ? $budget : null,
                'currency' => null,
                'targeting' => $this->readableTargeting($g),
                'starts_at' => isset($g['schedule_start_time']) ? (string) $g['schedule_start_time'] : null,
                'ends_at' => isset($g['schedule_end_time']) ? (string) $g['schedule_end_time'] : null,
                'raw' => (array) $g,
            ];
        }

        return $sets;
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('ad/get/'), [
                'advertiser_id' => $adAccountId,
                'page_size' => 100,
            ]),
            'ads',
        );

        $ads = [];

        foreach ((array) (($body['data']['list'] ?? [])) as $a) {
            if (($a['ad_id'] ?? null) === null) {
                continue;
            }

            $ads[] = array_filter([
                'external_id' => (string) $a['ad_id'],
                'ad_set_external_id' => isset($a['adgroup_id']) ? (string) $a['adgroup_id'] : null,
                'campaign_external_id' => isset($a['campaign_id']) ? (string) $a['campaign_id'] : null,
                'name' => (string) ($a['ad_name'] ?? $a['ad_id']),
                'status' => strtolower((string) ($a['operation_status'] ?? 'unknown')),
                'review_status' => $this->reviewStatus($a['secondary_status'] ?? null),
                'destination_url' => isset($a['landing_page_url']) ? (string) $a['landing_page_url'] : null,
                'creative' => $this->creativeOf($a),
                'raw' => (array) $a,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * TikTok has no creative OBJECT — the media is fields on the ad itself.
     *
     * So the creative's identity is the video or image the ad carries, and an ad that carries neither
     * gets no creative row rather than an empty one named after the ad. TikTok returns ids, not URLs,
     * so `thumbnail_url` stays null: fetching a signed media URL is a separate call per asset, and
     * inventing one from the id would produce a link that 404s in front of a client.
     *
     * @param  array<string,mixed>  $ad
     * @return array<string,mixed>|null
     */
    private function creativeOf(array $ad): ?array
    {
        $video = isset($ad['video_id']) ? (string) $ad['video_id'] : '';
        $images = array_values(array_filter((array) ($ad['image_ids'] ?? []), 'is_string'));

        $id = $video !== '' ? $video : ($images[0] ?? '');

        if ($id === '') {
            return null;
        }

        return [
            'external_id' => $id,
            'name' => (string) ($ad['ad_name'] ?? $id),
            'format' => $video !== '' ? 'video' : 'image',
        ];
    }

    /**
     * TikTok's `secondary_status` is a long enum covering review, delivery and budget together.
     *
     * Only the review answers are mapped; a status about pacing or an exhausted budget says nothing
     * about whether the ad was approved, and reporting it as a review verdict would be a guess.
     */
    private function reviewStatus(mixed $secondary): ?string
    {
        $status = strtoupper((string) $secondary);

        return match (true) {
            str_contains($status, 'AUDIT_DENY'), str_contains($status, 'REJECT') => 'rejected',
            str_contains($status, 'AUDIT') => 'pending',
            str_contains($status, 'DELIVER_OK') => 'approved',
            default => null,
        };
    }

    /**
     * The targeting keys a human reads, out of an ad group that states dozens.
     *
     * @param  array<string,mixed>  $group
     * @return array<string,mixed>|null
     */
    private function readableTargeting(array $group): ?array
    {
        $readable = array_filter([
            'locations' => $group['location_ids'] ?? null,
            'age_groups' => $group['age_groups'] ?? null,
            'gender' => isset($group['gender']) ? strtolower((string) $group['gender']) : null,
            'languages' => $group['languages'] ?? null,
            'placements' => $group['placements'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);

        return $readable === [] ? null : $readable;
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
