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
    /**
     * A ceiling on paging, so a wrong `total_page` cannot become an unbounded loop.
     *
     * At TikTok's page size of 100 this is far more entities than an advertiser holds; it exists
     * because a sync job that never returns is worse than one that stops and says it stopped.
     */
    private const MAX_PAGES = 50;

    /**
     * Canonical metric ← the TikTok field that actually means it (TIKTOK-001).
     *
     * Every line is a semantic decision, and the four this project forbids are all live here:
     *
     *  - `purchases` ← **`complete_payment`**, never `conversion`. TikTok's `conversion` counts every
     *    event the campaign was optimised for — a lead, an install, a registration, a form submit. On
     *    a lead-gen campaign it is a count of leads, and reporting it as purchases would tell a client
     *    they sold something. `conversion` is still carried, as `conversions`, because it IS the
     *    platform's own «results» figure; what it must never be is the sale.
     *  - `checkout` ← `initiate_checkout`, its own stage. A started checkout is not a completed one.
     *  - `add_to_cart` ← `add_to_cart`, never a content view. Looking at a product is not putting it
     *    in a basket.
     *  - `video_views` ← `video_play_actions` ALONE. `video_watched_2s` and `_6s` are the same
     *    viewers measured at longer thresholds, so adding them counts one person up to three times.
     *
     * Three canonical metrics are deliberately ABSENT rather than approximated:
     *
     *  - `frequency` is derived (impressions ÷ reach) and is computed at read time with a null on a
     *    zero denominator. A stored daily frequency summed across a month is a number with no referent.
     *  - `landing_page_views` has no TikTok equivalent. Its «Content views (page)» metric is the
     *    view-content event, which is a different thing measured at a different moment; using it would
     *    put a number under a label it does not belong to.
     *  - `engagements` would have to be `likes + comments + shares + follows`, a total TikTok never
     *    publishes. Summing them here would manufacture a metric the platform did not report.
     *
     * Unlike Snapchat, spend and value arrive in the advertiser's own currency rather than in
     * millionths, so there is no division at this edge.
     *
     * `initiate_checkout` is the one spelling not confirmed verbatim against the developer portal
     * (which renders no documentation to a fetcher). It is mapped anyway because a wrong spelling
     * FAILS SAFE by construction: TikTok returns no such key, `array_key_exists` skips it, nothing is
     * stored, and every surface says «لم تُرسل» rather than printing a measured zero. It is recorded
     * as the one line to confirm on the first real sync — which is what awaiting-credentials means.
     */
    private const METRICS = [
        'spend' => 'spend',
        'impressions' => 'impressions',
        'clicks' => 'clicks',
        'reach' => 'reach',
        'video_views' => 'video_play_actions',
        'video_completions' => 'video_views_p100',
        'add_to_cart' => 'add_to_cart',
        'checkout' => 'initiate_checkout',
        'purchases' => 'complete_payment',
        'revenue' => 'total_purchase_value',
        'conversions' => 'conversion',
    ];

    protected function platform(): string
    {
        return 'tiktok';
    }

    /**
     * Read a list endpoint to its END, not just its first page.
     *
     * TikTok pages every collection with `page`/`page_size` and states the extent in
     * `data.page_info.total_page`. Reading one page and stopping is not a partial answer that looks
     * partial — it is a complete-looking answer with entities missing, and the ones missing are
     * whichever the platform happened to order last. An advertiser with 140 ads silently reported
     * 100 of them, and the forty that vanished took their spend out of every total on every surface.
     *
     * The page NUMBER is followed rather than a cursor because that is what this API offers; the
     * caller's own query is carried forward on every request so a filter cannot be dropped after the
     * first page.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>> every object from `data.list`, across all pages
     */
    private function readAll(OAuthTokens $tokens, string $path, string $what, array $query): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->read(
                $this->api($tokens)->get($this->url($path), array_merge($query, [
                    'page' => $page,
                    'page_size' => 100,
                ])),
                $what,
            );

            foreach ((array) ($body['data']['list'] ?? []) as $item) {
                $items[] = (array) $item;
            }

            // Absent `page_info` means a single-page answer, not an unknown one — one read and stop.
            $total = (int) ($body['data']['page_info']['total_page'] ?? 1);

            if ($page >= max(1, $total)) {
                break;
            }
        }

        return $items;
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        /*
         * The advertiser list is the one call that still wants the app credentials in the query.
         *
         * Deliberately NOT routed through `readAll()`: it is keyed on the app rather than on an
         * advertiser, publishes no `page_info`, and returns every advertiser the token can reach in
         * one answer. Sending paging parameters to a call that may reject unknown ones would buy
         * nothing and could cost the whole discovery step.
         */
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
        $campaigns = [];

        foreach ($this->readAll($tokens, 'campaign/get/', 'campaigns', ['advertiser_id' => $adAccountId]) as $c) {
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
        $sets = [];

        foreach ($this->readAll($tokens, 'adgroup/get/', 'ad groups', ['advertiser_id' => $adAccountId]) as $g) {
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
        $ads = [];

        foreach ($this->readAll($tokens, 'ad/get/', 'ads', ['advertiser_id' => $adAccountId]) as $a) {
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
        $reported = $this->readAll($tokens, 'report/integrated/get/', 'daily report', [
            'advertiser_id' => $adAccountId,
            'report_type' => 'BASIC',
            'data_level' => 'AUCTION_CAMPAIGN',
            // TikTok wants these as JSON strings inside the query, not repeated parameters.
            'dimensions' => json_encode(['campaign_id', 'stat_time_day'], JSON_THROW_ON_ERROR),
            // Asked for FROM the map, so a metric cannot be mapped here and then never requested —
            // which reads downstream exactly like a platform that does not report it.
            'metrics' => json_encode(array_values(array_unique(self::METRICS)), JSON_THROW_ON_ERROR),
            'start_date' => $from,
            'end_date' => $to,
        ]);

        $this->countRawInsightRows(count($reported));

        $rows = [];

        foreach ($reported as $row) {
            /** @var array<string,mixed> $dims */
            $dims = (array) ($row['dimensions'] ?? []);
            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);

            $campaignId = (string) ($dims['campaign_id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $mapped = [
                'campaign_id' => $campaignId,
                // `stat_time_day` is «2026-08-05 00:00:00»; the pipeline stores a date.
                'date' => substr((string) ($dims['stat_time_day'] ?? $from), 0, 10),
            ];

            foreach (self::METRICS as $canonical => $field) {
                /*
                 * ABSENT, not zero.
                 *
                 * A metric this advertiser does not report must arrive as a MISSING KEY, so the
                 * pipeline stores no row for it and every surface says «لم تُرسل» rather than
                 * printing a measured zero. An awareness campaign that was never asked to sell
                 * anything has no purchases; it does not have zero of them.
                 *
                 * `array_key_exists` rather than `isset`, because TikTok sends a JSON null for some
                 * fields and `isset` cannot tell that from a key that was never there — both are
                 * «not reported» here, and both must be skipped rather than cast to 0.0.
                 */
                if (! array_key_exists($field, $metrics) || $metrics[$field] === null) {
                    continue;
                }

                // Numbers arrive as strings on this API; spend and value are in the advertiser's own
                // currency, not in millionths, so nothing is divided at this edge.
                $mapped[$canonical] = (float) $metrics[$field];
            }

            $rows[] = $mapped;
        }

        return $rows;
    }
}
