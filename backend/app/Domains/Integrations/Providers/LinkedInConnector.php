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
    /**
     * LINKEDIN-PAGE-001 — how many rows to ask for, because LinkedIn's own default is **ten**.
     *
     * Every list this connector read used to stop at that ten: at most ten ad accounts, and within
     * each of them at most ten campaigns, ten creatives and ten rows of analytics. Nothing errored and
     * nothing was logged — every total on every surface was simply short by whatever the eleventh
     * campaign onward did, which reads as a smaller account rather than as a broken integration.
     *
     * 100 rather than the 1000 LinkedIn permits: a page is a request that has to succeed, and the
     * ceiling below already bounds the number of them.
     */
    private const PAGE = 100;

    /** A bound on the walk, so a server that never shortens a page cannot cost us a worker. */
    private const MAX_PAGES = 50;

    /**
     * The widest window `approximateMemberReach` is defined for.
     *
     * LinkedIn: «This metric is only available when the number of days in the date range is less
     * than or equal to 92 days». Beyond it the field is not requested at all — see `fetchInsights`.
     */
    private const REACH_MAX_DAYS = 92;

    protected function platform(): string
    {
        return 'linkedin';
    }

    /**
     * Read a LinkedIn collection to its END.
     *
     * LinkedIn pages with `start` and `count`, and publishes the termination rule plainly: «You have
     * reached the end of the dataset when your response contains fewer elements … than your count
     * parameter request». That is what this uses, rather than paging until an empty page — which
     * would spend one extra round trip on every sync of every account, on an API that throttles
     * per application.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function readAll(OAuthTokens $tokens, string $path, string $what, array $query, array $restli = []): array
    {
        $items = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->read(
                $this->api($tokens)->get($this->url($path).'?'.$this->queryString([
                    ...$query,
                    'start' => $page * self::PAGE,
                    'count' => self::PAGE,
                ], $restli)),
                $what,
            );

            $elements = (array) ($body['elements'] ?? []);

            foreach ($elements as $element) {
                $items[] = (array) $element;
            }

            if (count($elements) < self::PAGE) {
                break;
            }
        }

        return $items;
    }

    /**
     * LINKEDIN-PROJECTION-001 — the query is built here, because Rest.li structure is not a value.
     *
     * `PendingRequest::get($url, $query)` builds the query with `http_build_query`, which
     * percent-encodes every comma. A Rest.li field projection is a comma-separated LIST whose commas
     * are syntax, so LinkedIn received one field named
     * «pivotValues%2CdateRange%2CcostInLocalCurrency%2C…» and answered, correctly, that no such field
     * exists in `AdAnalyticsV9`. Every daily analytics call failed on a live account whose OAuth,
     * ad account and campaigns had all been discovered successfully.
     *
     * It is not «encode nothing»: LinkedIn's own sample URL sends `fields=a,b,c` with literal commas
     * and `accounts=List(urn%3Ali%3AsponsoredAccount%3A502840441)` with an ENCODED urn in the same
     * line. So the split is explicit rather than clever — `$restli` names the parameters whose value
     * is already exactly what must appear on the wire, and everything else is encoded as a value.
     *
     * @param  array<string,mixed>  $query  ordinary values, encoded
     * @param  array<string,string>  $restli  Rest.li structure, sent verbatim
     */
    private function queryString(array $query, array $restli = []): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        foreach ($restli as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.$value;
        }

        return implode('&', $pairs);
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $accounts = [];

        foreach ($this->readAll($tokens, 'adAccounts', 'ad accounts', ['q' => 'search']) as $a) {
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
        $campaigns = [];

        foreach ($this->readAll($tokens, "adAccounts/{$adAccountId}/adCampaigns", 'campaigns', ['q' => 'search']) as $c) {
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
        $ads = [];

        foreach ($this->readAll($tokens, "adAccounts/{$adAccountId}/creatives", 'creatives', ['q' => 'criteria']) as $c) {
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
        /*
         * Paged like everything else (LINKEDIN-PAGE-001), and here the truncation was worst: one row
         * is one campaign on one day, so a month of a handful of campaigns exceeds ten rows
         * immediately. Reading the first page alone reported a fraction of the spend as though it
         * were the whole of it.
         */
        /*
         * LINKEDIN-REACH-001 — `approximateUniqueImpressions` is not a field any version this pin can
         * reach still defines.
         *
         * LinkedIn's Metrics Available table has no row for it. It has `approximateMemberReach`,
         * described as «an updated and more accurate version of legacy metric
         * approximateUniqueImpressions … fully launched in Jan 2024», and it states two conditions:
         * non-demographic pivots only — CAMPAIGN qualifies — and a date range of at most 92 days.
         *
         * So a backfill wider than 92 days does not ask for it. Asking for a field outside the
         * conditions its own schema states is the class of request that failed in production, and a
         * long window still returns spend, impressions, clicks and conversions.
         */
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;

        $fields = array_merge(
            ['pivotValues', 'dateRange', 'costInLocalCurrency', 'impressions', 'clicks', 'externalWebsiteConversions'],
            $days <= self::REACH_MAX_DAYS ? ['approximateMemberReach'] : [],
            ['videoViews', 'videoCompletions', 'totalEngagements', 'landingPageClicks'],
        );

        $reported = $this->readAll($tokens, 'adAnalytics', 'daily analytics', [
            'q' => 'analytics',
            'pivot' => 'CAMPAIGN',
            'timeGranularity' => 'DAILY',
        ], [
            /*
             * Rest.li structure, sent verbatim — see `queryString()`. The account URN is encoded
             * INSIDE the `List(…)` exactly as LinkedIn's own reference sends it; the parentheses,
             * the commas and the date tuple's colons are syntax and stay literal.
             */
            'dateRange' => $this->dateRange($from, $to),
            'accounts' => 'List('.rawurlencode("urn:li:sponsoredAccount:{$adAccountId}").')',
            'fields' => implode(',', $fields),
        ]);

        $this->countRawInsightRows(count($reported));

        $rows = [];

        foreach ($reported as $row) {
            $campaignId = $this->campaignIdFrom($row['pivotValues'] ?? null);
            $date = $this->dateFrom($row['dateRange'] ?? null);

            if ($campaignId === null || $date === null) {
                continue;
            }

            /*
             * LINKEDIN-001 — what LinkedIn measures, and the two things it does not.
             *
             * **`purchases` is absent, deliberately.** LinkedIn's adAnalytics has no purchase metric.
             * `externalWebsiteConversions` counts every conversion the account defined — a demo
             * request, a whitepaper download, a contact form, and on the rare B2C account a sale —
             * with no way to ask for one category, the way Google Ads can. So it is carried as
             * `conversions`, which is what it is, and `purchases` stays null rather than being
             * approximated from it. A sales funnel on a LinkedIn-only account therefore ends in «لم
             * تُرسل», which is the truth: LinkedIn did not tell us about sales.
             *
             * **`revenue` is absent for the same reason.** `conversionValueInLocalCurrency` is the
             * value the advertiser ASSIGNED to those conversions — commonly an internal worth put on
             * a lead. Reporting it as revenue would put a ROAS on the client's report built from
             * money nobody has taken. It was mapped here before this unit; removing it costs a
             * figure and buys back the only thing that makes the rest of them worth reading.
             *
             * `landing_page_views` also stays absent: `landingPageClicks` counts the CLICK, which is
             * the moment before the arrival that this canonical key means on every other platform.
             * It is requested so a future decision can be made on real data, and mapped to nothing.
             */
            $rows[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => $date,
                'spend' => isset($row['costInLocalCurrency']) ? (float) $row['costInLocalCurrency'] : null,
                'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
                'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
                // The closest thing LinkedIn publishes to reach: unique members shown the ad.
                'reach' => isset($row['approximateMemberReach'])
                    ? (float) $row['approximateMemberReach']
                    : null,
                'conversions' => isset($row['externalWebsiteConversions'])
                    ? (float) $row['externalWebsiteConversions']
                    : null,
                'video_views' => isset($row['videoViews']) ? (float) $row['videoViews'] : null,
                'video_completions' => isset($row['videoCompletions']) ? (float) $row['videoCompletions'] : null,
                // LinkedIn publishes a single engagement total, so it is read rather than assembled
                // from likes, comments and shares — which would count one interaction more than once.
                'engagements' => isset($row['totalEngagements']) ? (float) $row['totalEngagements'] : null,
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
