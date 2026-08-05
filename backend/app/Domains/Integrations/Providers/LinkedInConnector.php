<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use Illuminate\Support\Carbon;

/**
 * LinkedIn Marketing API (`api.linkedin.com/rest`).
 *
 * Everything is a URN and a Restli query string. Accounts come back as bare ids but every other
 * endpoint wants `urn:li:sponsoredAccount:{id}`, and the analytics call spells its date range out as
 * `(start:(year:2026,month:8,day:1),end:(...))` rather than ISO dates. Both conversions live here so
 * the rest of the system keeps dealing in plain ids and `YYYY-MM-DD`.
 *
 * The API version header is mandatory and monthly — an unpinned call is rejected outright, which is
 * why `LINKEDIN_ADS_VERSION` has a default rather than being optional.
 *
 * Awaiting credentials — LinkedIn's Marketing Developer Platform is an application, not a signup.
 */
final class LinkedInConnector extends ApiAdvertisingConnector
{
    protected function platform(): string
    {
        return 'linkedin';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('adAccounts'), ['q' => 'search']),
            'ad accounts',
        );

        $accounts = [];

        foreach ((array) ($body['elements'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                'timezone' => null,
                'status' => strtolower((string) ($a['status'] ?? 'active')),
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adAccounts/{$adAccountId}/adCampaigns"), ['q' => 'search']),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) ($body['elements'] ?? []) as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'status' => strtolower((string) ($c['status'] ?? 'unknown')),
                'objective' => isset($c['objectiveType']) ? (string) $c['objectiveType'] : null,
                // Money is `{ amount: "125.00", currencyCode: "USD" }`, as a string.
                'daily_budget' => $this->money($c['dailyBudget'] ?? null),
                'lifetime_budget' => $this->money($c['totalBudget'] ?? null),
                'currency' => is_array($c['dailyBudget'] ?? null) && isset($c['dailyBudget']['currencyCode'])
                    ? (string) $c['dailyBudget']['currencyCode']
                    : null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    /**
     * LinkedIn has no ad-set level, and this method says so by returning nothing.
     *
     * Its hierarchy is campaign group → campaign → creative. What this product already calls an
     * external campaign IS a LinkedIn campaign — the level that carries the targeting and the budget —
     * so beneath it there is a creative and nothing in between. Synthesising one ad set per campaign
     * to make the shape match the other five would put a row on the screen that LinkedIn never
     * returned; the schema lets an ad hang directly off its campaign instead (STRUCT-001).
     */
    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        return [];
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("adAccounts/{$adAccountId}/creatives"), ['q' => 'criteria']),
            'creatives',
        );

        $ads = [];

        foreach ((array) ($body['elements'] ?? []) as $c) {
            $id = $this->idFromUrn($c['id'] ?? null, 'sponsoredCreative');
            $campaignId = $this->idFromUrn($c['campaign'] ?? null, 'sponsoredCampaign');

            if ($id === null) {
                continue;
            }

            $ads[] = array_filter([
                'external_id' => $id,
                'ad_set_external_id' => null, // there is no such level on LinkedIn
                'campaign_external_id' => $campaignId,
                // LinkedIn creatives carry no name; the id is the only label it gives one.
                'name' => "Creative {$id}",
                'status' => strtolower((string) ($c['intendedStatus'] ?? 'unknown')),
                'review_status' => match (strtoupper((string) ($c['reviewStatus'] ?? ''))) {
                    'APPROVED' => 'approved',
                    'PENDING', 'IN_REVIEW' => 'pending',
                    'REJECTED' => 'rejected',
                    default => null,
                },
                'destination_url' => null,
                // The creative IS the ad here, so a separate creative row would be the same thing
                // twice under two names.
                'creative' => null,
                'raw' => (array) $c,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /** `urn:li:sponsoredCreative:123` → `"123"`, and anything else → null. */
    private function idFromUrn(mixed $urn, string $type): ?string
    {
        if (! is_string($urn) || ! str_contains($urn, $type.':')) {
            return null;
        }

        $id = substr($urn, strrpos($urn, ':') + 1);

        return $id === '' ? null : $id;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('adAnalytics'), [
                'q' => 'analytics',
                'pivot' => 'CAMPAIGN',
                'timeGranularity' => 'DAILY',
                'dateRange' => $this->dateRange($from, $to),
                'accounts' => "List(urn:li:sponsoredAccount:{$adAccountId})",
                'fields' => 'pivotValues,dateRange,costInLocalCurrency,impressions,clicks,externalWebsiteConversions,conversionValueInLocalCurrency,videoViews',
            ]),
            'daily analytics',
        );

        $rows = [];

        foreach ((array) ($body['elements'] ?? []) as $row) {
            $campaignId = $this->campaignIdFrom($row['pivotValues'] ?? null);
            $date = $this->dateFrom($row['dateRange'] ?? null);

            if ($campaignId === null || $date === null) {
                continue;
            }

            $rows[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => $date,
                'spend' => isset($row['costInLocalCurrency']) ? (float) $row['costInLocalCurrency'] : null,
                'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
                'conversions' => isset($row['externalWebsiteConversions'])
                    ? (float) $row['externalWebsiteConversions']
                    : null,
                'revenue' => isset($row['conversionValueInLocalCurrency'])
                    ? (float) $row['conversionValueInLocalCurrency']
                    : null,
                'video_views' => isset($row['videoViews']) ? (float) $row['videoViews'] : null,
            ], static fn ($v) => $v !== null);
        }

        return $rows;
    }

    /** `(start:(year:2026,month:8,day:1),end:(year:2026,month:8,day:5))` — LinkedIn's own spelling. */
    private function dateRange(string $from, string $to): string
    {
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);

        return sprintf(
            '(start:(year:%d,month:%d,day:%d),end:(year:%d,month:%d,day:%d))',
            $start->year, $start->month, $start->day,
            $end->year, $end->month, $end->day,
        );
    }

    /** `["urn:li:sponsoredCampaign:123"]` → `"123"`. */
    private function campaignIdFrom(mixed $pivotValues): ?string
    {
        if (! is_array($pivotValues)) {
            return null;
        }

        foreach ($pivotValues as $urn) {
            if (is_string($urn) && str_contains($urn, 'sponsoredCampaign:')) {
                return substr($urn, strrpos($urn, ':') + 1);
            }
        }

        return null;
    }

    /** The row's own `dateRange.start` triple → `YYYY-MM-DD`. */
    private function dateFrom(mixed $range): ?string
    {
        $start = is_array($range) ? ($range['start'] ?? null) : null;

        if (! is_array($start) || ! isset($start['year'], $start['month'], $start['day'])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $start['year'], (int) $start['month'], (int) $start['day']);
    }

    private function money(mixed $value): ?float
    {
        if (! is_array($value) || ! isset($value['amount'])) {
            return null;
        }

        return (float) $value['amount'];
    }
}
