<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Reporting\ReportingWindow;

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

    /**
     * A ceiling on paging, so a wrong `next_link` cannot become an unbounded loop.
     *
     * At Snapchat's default page size this is far more entities than an ad account holds; it exists
     * because a sync job that never returns is worse than one that stops and says it stopped.
     */
    private const MAX_PAGES = 50;

    /**
     * The documented maximum page size for stats — «The maximum pagination limit is 200.»
     *
     * Asked for explicitly rather than left to the default, because the default is small and the
     * cost of a page is a round trip against a per-application rate limit.
     */
    private const STATS_PAGE_SIZE = 200;

    /**
     * Read a list endpoint to its END, not just its first page.
     *
     * Snapchat pages every collection and hands back the next page as an absolute URL in
     * `paging.next_link`. Reading one page and stopping is not a partial answer that looks partial —
     * it is a complete-looking answer with entities missing, and the ones missing are the ones the
     * platform happened to order last. An account with 60 campaigns silently reported 50 of them, and
     * the ten that vanished took their spend out of every total on every surface.
     *
     * The cursor is followed rather than reconstructed: `next_link` already carries whatever page
     * token, limit and filter the first request implied, so rebuilding it by hand is how a paging
     * parameter gets dropped and the same page is fetched forever.
     *
     * @return list<array<string,mixed>> every wrapper object from `$key`, across all pages
     */
    private function readAll(OAuthTokens $tokens, string $path, string $key, string $what): array
    {
        $url = $this->url($path);
        $items = [];

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            $body = $this->read($this->api($tokens)->get($url), $what);

            foreach ((array) ($body[$key] ?? []) as $wrapper) {
                $items[] = (array) $wrapper;
            }

            $next = $body['paging']['next_link'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $items;
    }

    protected function platform(): string
    {
        return 'snapchat';
    }

    /**
     * The organisations THIS token can reach, with their ad accounts — SNAP-ORG-001.
     *
     * ## What this replaced, and why it had to
     *
     * It read `organization_id` out of the platform's own `/admin` settings and asked for
     * `organizations/{that one id}/adaccounts`. CampaignsHub is multi-tenant: every customer
     * authorises with their own Business Manager member, holding access to their own organisation.
     * One organisation id in one system row pointed every customer's token at the operator's
     * organisation — so a tenant saw accounts that were not theirs, or, far more often, none at all,
     * because their token has no access there and Snapchat refuses the call. **A system-level field
     * cannot be correct for more than one customer at a time**, which made it a tenancy defect rather
     * than a configuration inconvenience.
     *
     * `GET /me/organizations?with_ad_accounts=true` is the documented answer: the organisations the
     * authenticated member can reach, each with its ad accounts nested and the member's role on them.
     * The endpoint existed all along; the field was standing in for a call nobody made.
     *
     * ## Absent stays absent
     *
     * A payload that omits currency or timezone yields NULL, never a default. A guessed currency
     * silently mis-states every figure derived from it, and this product would rather report that it
     * does not know than convert with a number nobody supplied.
     */
    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $accounts = [];

        foreach ($this->readAll($tokens, 'me/organizations?with_ad_accounts=true', 'organizations', 'organisations') as $wrapper) {
            /** @var array<string,mixed> $organization */
            $organization = (array) ($wrapper['organization'] ?? []);
            $organizationId = isset($organization['id']) ? (string) $organization['id'] : null;

            if ($organizationId === null) {
                continue;
            }

            /** @var list<array<string,mixed>> $adAccounts */
            $adAccounts = (array) ($organization['ad_accounts'] ?? []);

            foreach ($adAccounts as $a) {
                if (($a['id'] ?? null) === null) {
                    continue;
                }

                $accounts[] = [
                    'external_id' => (string) $a['id'],
                    'name' => (string) ($a['name'] ?? $a['id']),
                    'currency' => isset($a['currency']) ? (string) $a['currency'] : null,
                    'timezone' => isset($a['timezone']) ? (string) $a['timezone'] : null,
                    'status' => strtolower((string) ($a['status'] ?? 'active')),
                    // The organisation this account genuinely belongs to, read from the response —
                    // not a constant every tenant would have shared.
                    'parent_external_id' => $organizationId,
                    'parent_name' => isset($organization['name']) ? (string) $organization['name'] : null,
                    'raw' => $a,
                ];
            }
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $campaigns = [];

        foreach ($this->readAll($tokens, "adaccounts/{$adAccountId}/campaigns", 'campaigns', 'campaigns') as $wrapper) {
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
        $squads = [];

        foreach ($this->readAll($tokens, "adaccounts/{$adAccountId}/adsquads", 'adsquads', 'ad squads') as $wrapper) {
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

        $ads = [];

        foreach ($this->readAll($tokens, "adaccounts/{$adAccountId}/ads", 'ads', 'ads') as $wrapper) {
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
        $creatives = [];

        foreach ($this->readAll($tokens, "adaccounts/{$adAccountId}/creatives", 'creatives', 'creatives') as $wrapper) {
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

    /**
     * The canonical metric ← the Snapchat field it is READ from, and nothing invented in between.
     *
     * Every line here is a semantic decision, and the wrong one is not a rounding error — it is a
     * different question answered under the same heading:
     *
     * - **`clicks` ← `swipes`.** A swipe-up IS the click on this platform. Mapping it is what makes a
     *   Snapchat CTR comparable to the other five on one chart.
     * - **`purchases` ← `conversion_purchases`, never `conversion_start_checkout`.** A checkout that
     *   was started is not a sale, and reporting one as the other inflates every ROAS on the page.
     * - **`add_to_cart` ← `conversion_add_cart`, never `conversion_view_content`.** Looking at a
     *   product is not putting it in a basket.
     * - **`landing_page_views` ← `landing_page_views`**, the delivery metric — NOT
     *   `conversion_page_views`, which is a pixel event with its own attribution window and would put
     *   two different measurements in one column.
     * - **`video_views` ← `video_views` alone.** `video_views_5s` and `_15s` are the SAME viewers
     *   counted at longer thresholds; adding them would report a fraction of an audience as extra
     *   people.
     * - **`checkout` ← `conversion_start_checkout`.** Correct as its own funnel stage, which is
     *   exactly why it must never stand in for a purchase.
     *
     * `frequency` is absent on purpose: it is derived (`impressions / reach`) and a stored daily
     * frequency summed over a month is a number with no meaning. `engagements` is absent because
     * Snapchat publishes `shares`, `saves` and `story_opens` separately and no total over them — so
     * any total we produced would be one the platform never reported.
     *
     * @var array<string,string> canonical key → Snapchat field
     */
    private const METRICS = [
        'spend' => 'spend',
        'impressions' => 'impressions',
        'clicks' => 'swipes',
        'reach' => 'uniques',
        'landing_page_views' => 'landing_page_views',
        'video_views' => 'video_views',
        'video_completions' => 'view_completion',
        'add_to_cart' => 'conversion_add_cart',
        'checkout' => 'conversion_start_checkout',
        'purchases' => 'conversion_purchases',
        'conversions' => 'conversion_purchases',
        'revenue' => 'conversion_purchases_value',
    ];

    /** The fields stated in micro-units of the account currency, divided once at this edge. */
    private const MONEY = ['spend', 'revenue'];

    /**
     * Daily stats for one ad account, broken down by campaign — SNAP-WINDOW-001, SNAP-PAGING-001.
     *
     * ## The live failure this replaced
     *
     * The first real Snapchat metrics sync returned **0 metrics** and «Request cannot be processed
     * due to validation error». The range was a string literal:
     *
     * ```php
     * 'start_time' => $from.'T00:00:00.000-00:00',
     * 'end_time'   => $to.'T00:00:00.000-00:00',
     * ```
     *
     * UTC midnight, for every account on the platform. Snapchat's measurement reference states the
     * rule outright — «time must be of day boundary, start_time and end_time must be both specified,
     * or neither» — and its own DAY responses carry the ad account's offset. For an account in
     * `Asia/Riyadh` that literal is 03:00 local, which is not a day boundary, so the request was
     * refused before a single figure was read. Structure synced and metrics did not, because
     * structure never calls `/stats`.
     *
     * ## And the first page was being treated as the whole answer
     *
     * `breakdown=campaign` returns one entity per campaign, and the reference gives the pagination
     * contract: `limit` up to 200, with `paging.next_link` for the rest. This read one response and
     * returned. An account with 201 campaigns reported the first 200 and lost the rest silently —
     * the same defect LinkedIn had at a page size of 10, which is why it was found there first.
     */
    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $window = ReportingWindow::localDays($this->accountTimezone($adAccountId), $from, $to);

        $rows = [];

        /*
         * One request per chunk — SNAP-WINDOW-001 §8.
         *
         * A first sync asks for thirty days at once, and a provider that caps a DAY range refuses
         * the WHOLE request rather than truncating it: the customer's very first sync would be the
         * one that fails. Each chunk is upserted idempotently on `(account, campaign, date, metric)`,
         * so splitting costs round trips and nothing else.
         */
        foreach ($window->chunked($this->maxDaysPerRequest()) as $chunk) {
            foreach ($this->fetchWindow($adAccountId, $tokens, $chunk) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One provider-valid window's worth of stats, read to its last page.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchWindow(string $adAccountId, OAuthTokens $tokens, ReportingWindow $window): array
    {
        $url = $this->url("adaccounts/{$adAccountId}/stats").'?'.http_build_query([
            'granularity' => 'DAY',
            'breakdown' => 'campaign',
            // Asked for from the map, so a metric cannot be mapped and then never requested.
            'fields' => implode(',', array_values(array_unique(self::METRICS))),
            'start_time' => $window->startIso(),
            'end_time' => $window->endIso(),
            // The documented maximum. Fewer pages is fewer round trips against a per-app rate limit.
            'limit' => self::STATS_PAGE_SIZE,
        ]);

        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            $body = $this->read($this->api($tokens)->get($url), 'daily stats');

            foreach ((array) ($body['timeseries_stats'] ?? []) as $wrapper) {
                /** @var array<string,mixed> $series */
                $series = (array) ((array) $wrapper)['timeseries_stat'] ?? [];

                foreach ($this->campaignSeries($series) as $campaignId => $points) {
                    // What Snapchat sent, counted before any of our guards can drop one.
                    $this->countRawInsightRows(count($points));

                    foreach ($points as $point) {
                        $rows[] = $this->pointToRow((string) $campaignId, (array) $point, $window->startIso());
                    }
                }
            }

            $next = $body['paging']['next_link'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $rows;
    }

    /**
     * The per-CAMPAIGN day series inside one `timeseries_stat` — SNAP-BREAKDOWN-001.
     *
     * ## The live defect this exists for
     *
     * The connector read `timeseries_stat.timeseries` and took `timeseries_stat.id` for a campaign.
     * With `breakdown=campaign` Snapchat returns neither. The series IS the ad account, and the
     * campaigns hang underneath it:
     *
     * ```json
     * {"timeseries_stat":{
     *    "id":"3072e77d-…","type":"AD_ACCOUNT",
     *    "breakdown_stats":{"campaign":[
     *      {"id":"20c79671-…","type":"CAMPAIGN","granularity":"DAY","timeseries":[{"stats":{…}}]}
     *    ]}}}
     * ```
     *
     * So `timeseries` was an absent key and the loop produced nothing — **zero rows, every thirty
     * minutes, for a live account that had returned 100.17 USD of spend, 44,396 impressions and two
     * purchases in the same body**. The run said «the provider returned no insight rows for this
     * window», which was the one thing the body disproved. Every test agreed with the connector
     * because the fixture invented the same shape; see `SnapchatReportingWindowTest::series()`.
     *
     * The unbroken-down shape is still read, second. A request without `breakdown` returns the
     * series keyed by the entity itself, and that is a real Snapchat response — supporting only the
     * shape we currently ask for would break the moment somebody asks a different question.
     *
     * @param  array<string,mixed>  $series  one `timeseries_stat`
     * @return array<string, list<mixed>> provider campaign id → its day points
     */
    private function campaignSeries(array $series): array
    {
        /** @var array<string,mixed> $breakdown */
        $breakdown = (array) ($series['breakdown_stats'] ?? []);

        if (isset($breakdown['campaign']) && is_array($breakdown['campaign'])) {
            $byCampaign = [];

            foreach ($breakdown['campaign'] as $entry) {
                /** @var array<string,mixed> $campaign */
                $campaign = (array) $entry;
                $id = (string) ($campaign['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                /*
                 * Merged rather than assigned. Snapchat may split one campaign across entries when a
                 * window is chunked, and assigning would keep only the last of them — a silent loss
                 * of every earlier day, which is precisely the class of bug this method exists for.
                 */
                $byCampaign[$id] = [...($byCampaign[$id] ?? []), ...array_values((array) ($campaign['timeseries'] ?? []))];
            }

            return $byCampaign;
        }

        // No breakdown: the series is the entity, and its id is what the rows belong to.
        $id = (string) ($series['id'] ?? '');

        if ($id === '' || ! isset($series['timeseries'])) {
            return [];
        }

        return [$id => array_values((array) $series['timeseries'])];
    }

    /**
     * One timeseries point, mapped onto canonical keys.
     *
     * The date is taken from the point's own `start_time`, which Snapchat returns on the ACCOUNT's
     * offset — so a day is the account's day, and a spend figure lands on the date the advertiser
     * would name rather than on whatever UTC made of it.
     *
     * @param  array<string,mixed>  $point
     * @return array<string,mixed>
     */
    private function pointToRow(string $campaignId, array $point, string $fallbackDate): array
    {
        /** @var array<string,mixed> $stats */
        $stats = (array) ($point['stats'] ?? []);

        $row = [
            'campaign_id' => $campaignId,
            'date' => substr((string) ($point['start_time'] ?? $fallbackDate), 0, 10),
        ];

        foreach (self::METRICS as $canonical => $field) {
            /*
             * ABSENT, not zero.
             *
             * A metric this account does not report must arrive as a missing key, so the pipeline
             * stores no row for it and every surface says «غير مُرسَل» rather than printing a
             * measured zero. An awareness campaign that was never asked to sell anything has no
             * purchases; it does not have zero of them.
             */
            if (! array_key_exists($field, $stats)) {
                continue;
            }

            $row[$canonical] = in_array($canonical, self::MONEY, true)
                ? (float) $stats[$field] / self::MICRO
                : (float) $stats[$field];
        }

        return $row;
    }

    /** The configured ceiling on how many local days one request may cover. */
    private function maxDaysPerRequest(): int
    {
        return max(1, (int) config('integrations.chunking.max_days_per_request', 7));
    }

    /**
     * The ad account's own timezone, read from what discovery recorded.
     *
     * Not guessed at, and not defaulted: a default is precisely what broke this. An account whose
     * timezone was never captured is one we cannot place a reporting day for, and `ReportingWindow`
     * says so rather than producing a figure that belongs to the wrong day.
     */
    private function accountTimezone(string $adAccountId): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $timezone = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $this->connection->getKey())
            ->where('external_id', $adAccountId)
            ->where('account_type', 'ad_account')
            ->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }
}
