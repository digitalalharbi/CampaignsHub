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

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adaccounts/{$adAccountId}/adsquads")),
            'ad squads',
        );

        $squads = [];

        foreach ((array) ($body['adsquads'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $s */
            $s = (array) ($wrapper['adsquad'] ?? []);

            if (($s['id'] ?? null) === null || ($s['campaign_id'] ?? null) === null) {
                continue;
            }

            $squads[] = [
                'external_id' => (string) $s['id'],
                'campaign_external_id' => (string) $s['campaign_id'],
                'name' => (string) ($s['name'] ?? $s['id']),
                'status' => strtolower((string) ($s['status'] ?? 'unknown')),
                'optimization_goal' => isset($s['optimization_goal']) ? strtolower((string) $s['optimization_goal']) : null,
                'bid_strategy' => isset($s['bid_strategy']) ? strtolower((string) $s['bid_strategy']) : null,
                'daily_budget' => isset($s['daily_budget_micro']) ? (float) $s['daily_budget_micro'] / self::MICRO : null,
                'lifetime_budget' => isset($s['lifetime_budget_micro']) ? (float) $s['lifetime_budget_micro'] / self::MICRO : null,
                'currency' => null,
                'targeting' => $this->readableTargeting($s['targeting'] ?? null),
                'starts_at' => isset($s['start_time']) ? (string) $s['start_time'] : null,
                'ends_at' => isset($s['end_time']) ? (string) $s['end_time'] : null,
                'raw' => $s,
            ];
        }

        return $squads;
    }

    /**
     * Snapchat's ads name a creative by id and say nothing else about it, so the creative list is
     * fetched too and joined here.
     *
     * The alternative — a creative row named after its ad — would put a made-up name and an unknown
     * format in front of a client, which is the kind of small fabrication this product does not make.
     * One extra call per account is a cheap price for the difference.
     */
    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $creatives = $this->creativesById($tokens, $adAccountId);

        $body = $this->read(
            $this->api($tokens)->get($this->url("adaccounts/{$adAccountId}/ads")),
            'ads',
        );

        $ads = [];

        foreach ((array) ($body['ads'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $a */
            $a = (array) ($wrapper['ad'] ?? []);

            if (($a['id'] ?? null) === null) {
                continue;
            }

            $creativeId = isset($a['creative_id']) ? (string) $a['creative_id'] : null;

            $ads[] = array_filter([
                'external_id' => (string) $a['id'],
                'ad_set_external_id' => isset($a['ad_squad_id']) ? (string) $a['ad_squad_id'] : null,
                // Snapchat states the campaign on the squad, not the ad; the importer resolves it.
                'campaign_external_id' => null,
                'name' => (string) ($a['name'] ?? $a['id']),
                'status' => strtolower((string) ($a['status'] ?? 'unknown')),
                'review_status' => $this->reviewStatus($a['review_status'] ?? null),
                'destination_url' => null,
                'creative' => $creativeId === null ? null : ($creatives[$creativeId] ?? ['external_id' => $creativeId]),
                'raw' => $a,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * @return array<string,array<string,mixed>> creative id → the creative row we can state honestly
     */
    private function creativesById(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adaccounts/{$adAccountId}/creatives")),
            'creatives',
        );

        $creatives = [];

        foreach ((array) ($body['creatives'] ?? []) as $wrapper) {
            /** @var array<string,mixed> $c */
            $c = (array) ($wrapper['creative'] ?? []);

            if (($c['id'] ?? null) === null) {
                continue;
            }

            $creatives[(string) $c['id']] = array_filter([
                'external_id' => (string) $c['id'],
                'name' => isset($c['name']) ? (string) $c['name'] : null,
                'format' => match (strtoupper((string) ($c['type'] ?? ''))) {
                    'SNAP_AD', 'LONGFORM_VIDEO' => 'video',
                    'WEB_VIEW', 'APP_INSTALL' => 'image',
                    'COLLECTION' => 'carousel',
                    default => null,
                },
                // Snapchat's preview needs a separately-signed URL; it is not in this body, so there
                // is nothing honest to put here.
            ], static fn ($v) => $v !== null);
        }

        return $creatives;
    }

    private function reviewStatus(mixed $status): ?string
    {
        return match (strtoupper((string) $status)) {
            'APPROVED' => 'approved',
            'PENDING' => 'pending',
            'REJECTED' => 'rejected',
            default => null,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readableTargeting(mixed $targeting): ?array
    {
        if (! is_array($targeting)) {
            return null;
        }

        /** @var array<string,mixed> $demographics */
        $demographics = (array) (($targeting['demographics'][0] ?? []));

        $readable = array_filter([
            'countries' => array_values(array_filter(array_map(
                static fn ($g) => is_array($g) ? ($g['country_code'] ?? null) : null,
                (array) ($targeting['geos'] ?? []),
            ))),
            'age_min' => $demographics['min_age'] ?? null,
            'age_max' => $demographics['max_age'] ?? null,
            'gender' => isset($demographics['gender']) ? strtolower((string) $demographics['gender']) : null,
            'languages' => $demographics['languages'] ?? null,
        ], static fn ($v) => $v !== null && $v !== []);

        return $readable === [] ? null : $readable;
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
