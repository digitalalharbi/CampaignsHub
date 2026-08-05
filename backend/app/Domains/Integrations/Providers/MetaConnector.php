<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;

/**
 * Meta Marketing API (Graph v21) — Facebook and Instagram.
 *
 * The awkward part is conversions. Meta does not return a `conversions` number; it returns an
 * `actions` array of every action type the campaign produced — page engagement, video views, link
 * clicks, purchases — and a matching `action_values` array for the money. Summing all of them would
 * count a page like as a purchase, so `PURCHASE_ACTIONS` names the ones this product means by
 * "conversion", and everything else is left where it is rather than quietly inflating a ROAS.
 *
 * Awaiting credentials on this install.
 */
final class MetaConnector extends ApiAdvertisingConnector
{
    /**
     * The action types counted as a conversion.
     *
     * Both spellings are real: `purchase` comes from the pixel, `omni_purchase` from the aggregated
     * cross-surface attribution. A campaign can report either, and reading only one silently halves
     * the conversions on the accounts that report the other.
     *
     * @var list<string>
     */
    private const PURCHASE_ACTIONS = ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'];

    protected function platform(): string
    {
        return 'meta';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('me/adaccounts'), [
                'fields' => 'id,account_id,name,currency,timezone_name,account_status',
                'limit' => 200,
            ]),
            'ad accounts',
        );

        $accounts = [];

        foreach ((array) ($body['data'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                // `id` is already `act_<number>`, which is the form every other endpoint wants.
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                'timezone' => isset($a['timezone_name']) ? (string) $a['timezone_name'] : null,
                'status' => ((int) ($a['account_status'] ?? 1)) === 1 ? 'active' : 'inactive',
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/campaigns"), [
                'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time',
                'limit' => 500,
            ]),
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
                'status' => strtolower((string) ($c['status'] ?? 'unknown')),
                'objective' => isset($c['objective']) ? (string) $c['objective'] : null,
                // Budgets arrive in minor units of the account currency (halalas, cents).
                'daily_budget' => isset($c['daily_budget']) ? (float) $c['daily_budget'] / 100 : null,
                'lifetime_budget' => isset($c['lifetime_budget']) ? (float) $c['lifetime_budget'] / 100 : null,
                'currency' => null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/adsets"), [
                'fields' => 'id,name,status,campaign_id,optimization_goal,bid_strategy,daily_budget,lifetime_budget,targeting,start_time,end_time',
                'limit' => 500,
            ]),
            'ad sets',
        );

        $sets = [];

        foreach ((array) ($body['data'] ?? []) as $s) {
            if (($s['id'] ?? null) === null || ($s['campaign_id'] ?? null) === null) {
                continue;
            }

            $sets[] = [
                'external_id' => (string) $s['id'],
                'campaign_external_id' => (string) $s['campaign_id'],
                'name' => (string) ($s['name'] ?? $s['id']),
                'status' => strtolower((string) ($s['status'] ?? 'unknown')),
                'optimization_goal' => isset($s['optimization_goal']) ? strtolower((string) $s['optimization_goal']) : null,
                'bid_strategy' => isset($s['bid_strategy']) ? strtolower((string) $s['bid_strategy']) : null,
                // Minor units of the account currency, as with campaigns.
                'daily_budget' => isset($s['daily_budget']) ? (float) $s['daily_budget'] / 100 : null,
                'lifetime_budget' => isset($s['lifetime_budget']) ? (float) $s['lifetime_budget'] / 100 : null,
                'currency' => null,
                'targeting' => $this->readableTargeting($s['targeting'] ?? null),
                'starts_at' => isset($s['start_time']) ? (string) $s['start_time'] : null,
                'ends_at' => isset($s['end_time']) ? (string) $s['end_time'] : null,
                'raw' => (array) $s,
            ];
        }

        return $sets;
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/ads"), [
                'fields' => 'id,name,status,effective_status,adset_id,campaign_id,preview_shareable_link,creative{id,name,thumbnail_url,object_type}',
                'limit' => 500,
            ]),
            'ads',
        );

        $ads = [];

        foreach ((array) ($body['data'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            /** @var array<string,mixed> $creative */
            $creative = (array) ($a['creative'] ?? []);

            $ads[] = array_filter([
                'external_id' => (string) $a['id'],
                'ad_set_external_id' => isset($a['adset_id']) ? (string) $a['adset_id'] : null,
                'campaign_external_id' => isset($a['campaign_id']) ? (string) $a['campaign_id'] : null,
                'name' => (string) ($a['name'] ?? $a['id']),
                'status' => strtolower((string) ($a['status'] ?? 'unknown')),
                'review_status' => $this->reviewStatus($a['effective_status'] ?? null),
                'destination_url' => null, // Meta states it inside the creative's story spec, not on the ad
                'creative' => isset($creative['id']) ? array_filter([
                    'external_id' => (string) $creative['id'],
                    'name' => isset($creative['name']) ? (string) $creative['name'] : null,
                    'format' => $this->creativeFormat($creative['object_type'] ?? null),
                    // Passed through when Meta sends one; never constructed.
                    'thumbnail_url' => isset($creative['thumbnail_url']) ? (string) $creative['thumbnail_url'] : null,
                    'preview_url' => isset($a['preview_shareable_link']) ? (string) $a['preview_shareable_link'] : null,
                ], static fn ($v) => $v !== null) : null,
                'raw' => (array) $a,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * Meta's `effective_status` answers two questions at once, and only one of them is about review.
     *
     * `PAUSED`, `CAMPAIGN_PAUSED` and `ADSET_PAUSED` say who turned the ad off — nothing about whether
     * it was ever approved. Mapping them onto a review verdict would put «معتمد» or «مرفوض» on an ad
     * whose review Meta has not commented on, so anything that is not a review answer stays null.
     */
    private function reviewStatus(mixed $effective): ?string
    {
        return match (strtoupper((string) $effective)) {
            'ACTIVE' => 'approved',
            'PENDING_REVIEW', 'IN_PROCESS' => 'pending',
            'DISAPPROVED', 'WITH_ISSUES' => 'rejected',
            default => null,
        };
    }

    private function creativeFormat(mixed $objectType): ?string
    {
        return match (strtoupper((string) $objectType)) {
            'VIDEO' => 'video',
            'PHOTO', 'SHARE' => 'image',
            'DOMAIN' => 'carousel',
            default => null,
        };
    }

    /**
     * Meta's targeting object is enormous and mostly ids; the panel shows the few keys a human reads.
     *
     * The whole object is kept in `raw` regardless, so nothing is lost — this decides only what is
     * rendered as a chip, and a wall of numeric interest ids is not information.
     *
     * @return array<string,mixed>|null
     */
    private function readableTargeting(mixed $targeting): ?array
    {
        if (! is_array($targeting)) {
            return null;
        }

        $readable = array_filter([
            'countries' => $targeting['geo_locations']['countries'] ?? null,
            'cities' => array_map(
                static fn (array $c): string => (string) ($c['name'] ?? ''),
                array_filter((array) ($targeting['geo_locations']['cities'] ?? []), 'is_array'),
            ) ?: null,
            'age_min' => $targeting['age_min'] ?? null,
            'age_max' => $targeting['age_max'] ?? null,
            'genders' => $targeting['genders'] ?? null,
            'platforms' => $targeting['publisher_platforms'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);

        return $readable === [] ? null : $readable;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("{$adAccountId}/insights"), [
                'level' => 'campaign',
                'time_increment' => 1, // one row per campaign per day
                'fields' => 'campaign_id,date_start,spend,impressions,clicks,reach,actions,action_values,video_30_sec_watched_actions',
                'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
                'limit' => 500,
            ]),
            'daily insights',
        );

        $rows = [];

        foreach ((array) ($body['data'] ?? []) as $row) {
            $campaignId = (string) ($row['campaign_id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $rows[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => (string) ($row['date_start'] ?? $from),
                'spend' => isset($row['spend']) ? (float) $row['spend'] : null,
                'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
                'reach' => isset($row['reach']) ? (float) $row['reach'] : null,
                'conversions' => $this->sumActions($row['actions'] ?? null),
                'revenue' => $this->sumActions($row['action_values'] ?? null),
                'video_views' => $this->sumActions($row['video_30_sec_watched_actions'] ?? null, all: true),
            ], static fn ($v) => $v !== null);
        }

        return $rows;
    }

    /**
     * Sum a Meta action array, counting only purchases unless told otherwise.
     *
     * Returns null rather than 0.0 when the array is absent, because "Meta sent no actions" and "Meta
     * sent zero purchases" are different facts and only the second is a figure worth storing.
     */
    private function sumActions(mixed $actions, bool $all = false): ?float
    {
        if (! is_array($actions)) {
            return null;
        }

        $total = 0.0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            if (! $all && ! in_array((string) ($action['action_type'] ?? ''), self::PURCHASE_ACTIONS, true)) {
                continue;
            }

            $total += (float) ($action['value'] ?? 0);
        }

        return $total;
    }
}
