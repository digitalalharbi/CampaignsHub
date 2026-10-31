<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
final class TikTokConnector extends ApiAdvertisingConnector implements ReportsCreativeInsights
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
     *  - `add_to_cart` ← **`web_event_add_to_cart`** («Adds to cart (website)»). The first real sync
     *    (2026-10-12) asked for `add_to_cart`, which is not a report metric, and TikTok refused the
     *    WHOLE daily report (`code 40002 — Invalid metric fields: ['add_to_cart']`). The app event
     *    (`app_event_add_to_cart`) counts UNIQUE app adds — another event, another unit — and is not
     *    added to it.
     *  - `revenue` ← **`total_complete_payment_rate`** («Purchase value (website)»), the value of the
     *    same website purchases `complete_payment` counts. `total_purchase_value` is «Purchase value
     *    (app)»: it priced app purchases beside a website purchase count.
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
     * Every field below is checked verbatim against «Basic report supported metrics» (v1.3,
     * business-api.tiktok.com/portal/docs/basic-reports-supported-metrics/v1.3) and held by
     * `TikTokMetricAllowlistTest`. The note below predates that check and is kept for its reasoning:
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
        'add_to_cart' => 'web_event_add_to_cart',
        'checkout' => 'initiate_checkout',
        'purchases' => 'complete_payment',
        'revenue' => 'total_complete_payment_rate',
        'conversions' => 'conversion',
        // NASEL — «Form submissions (TikTok)»: leads submitted on TikTok's own Instant Form (v1.3 basic report metrics, read 2026-10-28).
        // Never `form`, which is «Form submissions (website)» — a pixel event on the landing page — and never `conversion`.
        'form_leads' => 'onsite_form',
    ];

    /** The figures a report is not a report without — never dropped to save a refused request. */
    private const DELIVERY = ['spend', 'impressions', 'clicks'];

    protected function platform(): string
    {
        return 'tiktok';
    }

    /**
     * The metric names a 40002 refusal lists: «Invalid metric fields: ['add_to_cart', 'x']».
     *
     * @return list<string>
     */
    private static function refusedFieldsIn(string $message): array
    {
        if (! preg_match('/Invalid metric fields:\s*\[([^\]]*)\]/i', $message, $m)) {
            return [];
        }
        preg_match_all("/['\"]([a-z0-9_]+)['\"]/i", $m[1], $names);

        return array_values(array_unique($names[1]));
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

        return $this->withAccountSettings($tokens, $accounts);
    }

    /**
     * TIKTOK-CURRENCY-001 — the account's own currency and timezone, asked of `advertiser/info/`.
     *
     * `oauth2/advertiser/get/` answers with an id and a name and, in Production, nothing else, so
     * every TikTok account was discovered with currency NULL. The normaliser then fell back to the
     * project's reporting currency and stored a SAR account's spend as if it were USD — the figures
     * were the right numbers in the wrong unit. Asked only for accounts that came back without a
     * currency, so an answer that already states one costs nothing extra.
     *
     * Failure here never fails discovery: an account with an unknown currency is still an account.
     *
     * @param  list<array<string,mixed>>  $accounts
     * @return list<array<string,mixed>>
     */
    private function withAccountSettings(OAuthTokens $tokens, array $accounts): array
    {
        $missing = array_values(array_map(
            static fn (array $a): string => (string) $a['external_id'],
            array_filter($accounts, static fn (array $a): bool => ($a['currency'] ?? null) === null),
        ));

        if ($missing === []) {
            return $accounts;
        }

        try {
            $settings = $this->advertiserSettings($tokens, $missing);
        } catch (RuntimeException $e) {
            Log::warning('TikTok advertiser settings could not be read; currency left unknown.', ['reason' => $e->getMessage()]);

            return $accounts;
        }

        foreach ($accounts as $i => $account) {
            $known = $settings[(string) $account['external_id']] ?? null;
            if ($known !== null) {
                $accounts[$i]['currency'] ??= $known['currency'];
                $accounts[$i]['timezone'] ??= $known['timezone'];
            }
        }

        return $accounts;
    }

    /** The Business Center that owns this advertiser, or null when the platform does not say. */
    private function ownerBusinessCenter(OAuthTokens $tokens, string $adAccountId): ?string
    {
        try {
            $body = $this->read(
                $this->api($tokens)->get($this->url('advertiser/info/'), [
                    'advertiser_ids' => json_encode([$adAccountId], JSON_THROW_ON_ERROR),
                    'fields' => json_encode(['advertiser_id', 'owner_bc_id'], JSON_THROW_ON_ERROR),
                ]),
                'advertiser owner',
            );
        } catch (RuntimeException $e) {
            Log::warning('TikTok owner Business Center could not be read; Spark posters are asked without it.', ['reason' => $e->getMessage()]);

            return null;
        }

        foreach ((array) ($body['data']['list'] ?? []) as $a) {
            if (is_array($a) && isset($a['owner_bc_id']) && is_string($a['owner_bc_id']) && $a['owner_bc_id'] !== '') {
                return $a['owner_bc_id'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{currency: ?string, timezone: ?string}>
     */
    private function advertiserSettings(OAuthTokens $tokens, array $ids): array
    {
        $settings = [];

        // TikTok accepts at most 100 advertiser ids per call.
        foreach (array_chunk($ids, 100) as $chunk) {
            $body = $this->read(
                $this->api($tokens)->get($this->url('advertiser/info/'), [
                    'advertiser_ids' => json_encode($chunk, JSON_THROW_ON_ERROR),
                    'fields' => json_encode(['advertiser_id', 'currency', 'timezone', 'display_timezone'], JSON_THROW_ON_ERROR),
                ]),
                'advertiser info',
            );

            foreach ((array) ($body['data']['list'] ?? []) as $a) {
                if (! is_array($a) || ($a['advertiser_id'] ?? null) === null) {
                    continue;
                }

                $currency = isset($a['currency']) && is_string($a['currency']) && $a['currency'] !== ''
                    ? strtoupper($a['currency'])
                    : null;

                // `display_timezone` is the IANA name («Asia/Riyadh»); `timezone` is an offset label.
                $timezone = null;
                foreach (['display_timezone', 'timezone'] as $key) {
                    $candidate = $a[$key] ?? null;
                    if (is_string($candidate) && in_array($candidate, \DateTimeZone::listIdentifiers(), true)) {
                        $timezone = $candidate;
                        break;
                    }
                }

                $settings[(string) $a['advertiser_id']] = ['currency' => $currency, 'timezone' => $timezone];
            }
        }

        return $settings;
    }

    /**
     * The currency the account's money is in: what discovery recorded, or — for an account discovered
     * before TIKTOK-CURRENCY-001 — asked once now and recorded, so the next sync does not ask again.
     */
    private function accountCurrency(OAuthTokens $tokens, string $adAccountId): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $account = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $this->connection->getKey())
            ->where('external_id', $adAccountId)
            ->where('account_type', 'ad_account')
            ->first();

        if ($account === null) {
            return null;
        }

        if (is_string($account->currency) && $account->currency !== '') {
            return strtoupper($account->currency);
        }

        try {
            $known = $this->advertiserSettings($tokens, [$adAccountId])[$adAccountId] ?? null;
        } catch (RuntimeException $e) {
            Log::warning('TikTok advertiser settings could not be read; currency left unknown.', [
                'advertiser_id' => $adAccountId,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($known === null || $known['currency'] === null) {
            return null;
        }

        $account->forceFill(array_filter([
            'currency' => $known['currency'],
            'timezone' => ($account->timezone === null || $account->timezone === '') ? $known['timezone'] : null,
        ], static fn ($v) => $v !== null))->save();

        return $known['currency'];
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

        return $this->withMedia($tokens, $adAccountId, $ads);
    }

    /**
     * TIKTOK-CONTENT-001 — the poster and the playable file, from TikTok's Asset Library.
     *
     * An ad names its media by id only. `GET /file/video/ad/info/` (≤ 60 `video_ids` a call) answers
     * `video_cover_url` and `preview_url`, temporary links valid for six hours with
     * `preview_url_expire_time` (UTC); `GET /file/image/ad/info/` (≤ 100 `image_ids`) answers
     * `image_url`, valid for one hour — both as TikTok's v1.3 reference documents them. The expiry is
     * stored so the media refresh renews them instead of a card showing a dead link. A Spark Ad pulls
     * an organic post (`tiktok_item_id`), which is not in the Asset Library: it keeps its identity,
     * copy and figures, and no poster is invented for it.
     *
     * A refused media call costs the posters only, never the structure that carries them.
     *
     * @param  list<array<string,mixed>>  $ads
     * @return list<array<string,mixed>>
     */
    /** Spark posters asked for in one sweep — a call each, so bounded. */
    private const SPARK_POSTER_CAP = 400;

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string,mixed>>
     */
    private function videoInfo(OAuthTokens $tokens, string $adAccountId, array $ids, int &$budget): array
    {
        if ($ids === [] || $budget <= 0) {
            return [];
        }

        $budget--;

        try {
            $body = $this->read($this->api($tokens)->get($this->url('file/video/ad/info/'), [
                'advertiser_id' => $adAccountId,
                'video_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
            ]), 'video info');
        } catch (RuntimeException $e) {
            if (count($ids) === 1) {
                Log::warning('TikTok refused one video; that creative keeps no poster.', ['reason' => $e->getMessage()]);

                return [];
            }

            $half = intdiv(count($ids), 2);

            return $this->videoInfo($tokens, $adAccountId, array_slice($ids, 0, $half), $budget)
                + $this->videoInfo($tokens, $adAccountId, array_slice($ids, $half), $budget);
        }

        $out = [];
        foreach ((array) ($body['data']['list'] ?? []) as $v) {
            if (is_array($v) && isset($v['video_id'])) {
                $out[(string) $v['video_id']] = $v;
            }
        }

        return $out;
    }

    private function withMedia(OAuthTokens $tokens, string $adAccountId, array $ads): array
    {
        $videoIds = [];
        $imageIds = [];
        foreach ($ads as $ad) {
            $creative = (array) ($ad['creative'] ?? []);
            $kind = $creative['raw']['media'] ?? null;
            if ($kind === 'video') {
                $videoIds[] = (string) $creative['external_id'];
            } elseif ($kind === 'image') {
                $imageIds[] = (string) $creative['external_id'];
            }
        }

        /*
         * TIKTOK-SPARK-COVER-001 — a Spark Ad runs an organic post, which is not in the Asset Library, so
         * `file/video/ad/info/` never answers for it. Production 2026-10-17: 283 of the 331 TikTok
         * creatives drew nothing — the Spark ones. The post's own cover comes from the identity that
         * owns it: `identity/video/info/` with the ad's identity and the post id, answering
         * `video_info.poster_url`. One call per post, capped, and a refusal costs that poster only.
         */
        $sparks = [];
        foreach ($ads as $ad) {
            $creative = (array) ($ad['creative'] ?? []);
            $raw = (array) ($ad['raw'] ?? []);
            if (($creative['raw']['media'] ?? null) === 'spark' && ! isset($sparks[(string) $creative['external_id']])) {
                $sparks[(string) $creative['external_id']] = array_filter([
                    'identity_type' => $raw['identity_type'] ?? null,
                    'identity_id' => $raw['identity_id'] ?? null,
                    'identity_authorized_bc_id' => $raw['identity_authorized_bc_id'] ?? null,
                ], static fn ($v) => is_string($v) && $v !== '');
            }
        }

        $videos = [];
        $images = [];
        $posts = [];
        $ownerBc = null;
        foreach (array_slice($sparks, 0, self::SPARK_POSTER_CAP, true) as $itemId => $identity) {
            if (! isset($identity['identity_type'], $identity['identity_id'])) {
                continue;
            }

            /*
             * TIKTOK-SPARK-COVER-001 (evidence 2026-10-18) — every live call answered code 40000, «Unable to
             * fetch a valid item. Please check the 'identity_id'», because the identities are `BC_AUTH_TT`:
             * a post authorised to a Business Center. TikTok names the Business Center as part of that
             * identity (`identity_authorized_bc_id`), and the ads' own bodies do not carry it, so the
             * question was asked without half its address. The advertiser's `owner_bc_id` is that
             * Business Center; it is read once per account and only when an identity lacks its own.
             */
            if ($identity['identity_type'] === 'BC_AUTH_TT' && ! isset($identity['identity_authorized_bc_id'])) {
                $ownerBc ??= $this->ownerBusinessCenter($tokens, $adAccountId) ?? false;
                if ($ownerBc !== false) {
                    $identity['identity_authorized_bc_id'] = $ownerBc;
                }
            }
            try {
                $body = $this->read($this->api($tokens)->get($this->url('identity/video/info/'), [
                    'advertiser_id' => $adAccountId,
                    'item_id' => $itemId,
                ] + $identity), 'spark post info');
                /*
                 * TIKTOK-SPARK-COVER-001 (evidence 2026-10-18) — the answer is `data.video_detail.video_info`.
                 *
                 * 154 of 163 live calls succeeded and every one was read from `data.video_info`, which TikTok
                 * does not send, so a successful answer produced no poster and 283 creatives kept drawing
                 * nothing. The receipt's key names showed the real nesting; the old position is still read
                 * for an answer that uses it.
                 */
                $info = (array) ($body['data']['video_detail']['video_info'] ?? $body['data']['video_info'] ?? []);
                if ($info !== []) {
                    $posts[$itemId] = $info;
                }
            } catch (RuntimeException $e) {
                Log::warning('TikTok Spark post info could not be read; that creative keeps no poster.', ['reason' => $e->getMessage()]);
            }
        }

        /*
         * TIKTOK-VIDEO-INFO-SPLIT-001 — one refused video no longer costs the other fifty-nine.
         *
         * Production 2026-10-18: `file/video/ad/info/` answered «Insufficient permissions for some videos»
         * for a chunk, and the whole chunk — and, because one try wrapped the loop, every chunk after it —
         * was lost, leaving only 43 of 326 videos with a poster. A refused chunk is now halved and asked
         * again until the refused ids stand alone and are dropped; the permitted ones keep their poster.
         * Bounded, so a wholly refused account costs a few dozen calls and not one per video.
         */
        $budget = 80;
        foreach (array_chunk(array_values(array_unique($videoIds)), 60) as $chunk) {
            foreach ($this->videoInfo($tokens, $adAccountId, $chunk, $budget) as $id => $v) {
                $videos[$id] = $v;
            }
        }

        /*
         * TIKTOK-MEDIA-001 — one refused chunk used to drop every image chunk after it (one try around the whole
         * loop), so a single bad id left a page of image ads with no poster. Each chunk is its own attempt now.
         */
        foreach (array_chunk(array_values(array_unique($imageIds)), 100) as $chunk) {
            try {
                $body = $this->read($this->api($tokens)->get($this->url('file/image/ad/info/'), [
                    'advertiser_id' => $adAccountId,
                    'image_ids' => json_encode($chunk, JSON_THROW_ON_ERROR),
                ]), 'image info');
                foreach ((array) ($body['data']['list'] ?? []) as $i) {
                    if (is_array($i) && isset($i['image_id'])) {
                        $images[(string) $i['image_id']] = $i;
                    }
                }
            } catch (RuntimeException $e) {
                Log::warning('TikTok image info could not be read for one chunk; those creatives keep no poster.', ['reason' => $e->getMessage(), 'image_ids' => $chunk]);
            }
        }

        foreach ($ads as $n => $ad) {
            $creative = $ad['creative'] ?? null;
            if (! is_array($creative)) {
                continue;
            }
            $id = (string) $creative['external_id'];
            if (isset($videos[$id])) {
                $v = $videos[$id];
                $creative += array_filter([
                    'thumbnail_url' => is_string($v['video_cover_url'] ?? null) ? $v['video_cover_url'] : null,
                    'video_url' => is_string($v['preview_url'] ?? null) ? $v['preview_url'] : null,
                    'asset_expires_at' => is_string($v['preview_url_expire_time'] ?? null) ? $v['preview_url_expire_time'].' UTC' : null,
                    // The file's own dimensions and length, when TikTok states them — never estimated.
                    'width' => is_numeric($v['width'] ?? null) ? (int) $v['width'] : null,
                    'height' => is_numeric($v['height'] ?? null) ? (int) $v['height'] : null,
                    'duration_seconds' => is_numeric($v['duration'] ?? null) ? (float) $v['duration'] : null,
                ], static fn ($x) => $x !== null);
            } elseif (isset($posts[$id])) {
                $v = $posts[$id];
                // Measured 2026-10-18: video_info carries bit_rate, duration, format, height, poster_url, signature, size, url, width.
                $poster = collect(['poster_url', 'cover_image_url', 'cover_url', 'video_cover_url'])->map(fn ($k) => $v[$k] ?? null)->first(fn ($u) => is_string($u) && $u !== '');
                $file = collect(['preview_url', 'url', 'video_url', 'play_url'])->map(fn ($k) => $v[$k] ?? null)->first(fn ($u) => is_string($u) && $u !== '');
                $creative += array_filter([
                    'thumbnail_url' => $poster,
                    'video_url' => $file,
                ], static fn ($x) => $x !== null);
            } elseif (isset($images[$id])) {
                $url = is_string($images[$id]['image_url'] ?? null) ? $images[$id]['image_url'] : null;
                $creative += array_filter([
                    'asset_url' => $url,
                    'thumbnail_url' => $url,
                    // «valid for an hour»: renewed by the next structure sweep.
                    'asset_expires_at' => $url === null ? null : now()->addHour()->toIso8601String(),
                ], static fn ($x) => $x !== null);
            }
            /*
             * Say, per ad, WHY nothing can be shown — on the creative itself, so the presenter and a log reader
             * see the same reason: which asset kind the ad declared and which lookup gave nothing back.
             */
            if (! isset($creative['thumbnail_url']) && ! isset($creative['asset_url']) && ! isset($creative['video_url'])) {
                $media = (string) (((array) ($creative['raw'] ?? []))['media'] ?? 'none');
                $reason = match ($media) {
                    'video' => 'video_info_missing',
                    'image' => 'image_info_missing',
                    'spark' => isset($ad['raw']['identity_type'], $ad['raw']['identity_id']) ? 'spark_post_info_missing' : 'spark_identity_unknown',
                    default => 'no_asset_declared',
                };
                $creative['raw'] = ((array) ($creative['raw'] ?? [])) + ['media_reason' => $reason];
                Log::info('TikTok creative has no displayable asset.', ['ad' => $ad['external_id'] ?? null, 'creative' => $id, 'media' => $media, 'reason' => $reason]);
            }
            $ads[$n]['creative'] = $creative;
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
        /*
         * TIKTOK-CONTENT-001 — a Spark Ad's `video_id` is null by TikTok's own definition: it pulls an
         * organic post, named by `tiktok_item_id`. 1,275 of the live account's 1,449 ads carried no
         * creative because this was not read, so their content never reached any surface.
         */
        $spark = isset($ad['tiktok_item_id']) ? (string) $ad['tiktok_item_id'] : '';

        [$id, $media] = match (true) {
            $video !== '' => [$video, 'video'],
            $images !== [] => [$images[0], 'image'],
            $spark !== '' => [$spark, 'spark'],
            default => ['', null],
        };

        if ($id === '') {
            return null;
        }

        return array_filter([
            'external_id' => $id,
            'name' => (string) ($ad['ad_name'] ?? $id),
            'format' => $media === 'image' ? 'image' : 'video',
            'destination_url' => isset($ad['landing_page_url']) && is_string($ad['landing_page_url']) ? $ad['landing_page_url'] : null,
            'raw' => array_filter([
                'media' => $media,
                'ad_format' => $ad['ad_format'] ?? null,
                'ad_text' => $ad['ad_text'] ?? null,
                'call_to_action' => $ad['call_to_action'] ?? null,
                'tiktok_item_id' => $spark === '' ? null : $spark,
            ], static fn ($v) => $v !== null && $v !== ''),
        ], static fn ($v) => $v !== null);
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
        /*
         * TIKTOK-METRIC-REFUSAL-001 — one refused metric must not cost the whole report.
         *
         * TikTok refuses the entire request when ANY metric name is invalid (`code 40002`, «Invalid
         * metric fields: ['add_to_cart']»), so a single stage it does not offer wiped out spend,
         * impressions and results for the account. The refusal names the fields, so they are left out
         * and the report is asked again; what was left out arrives as an absent key, which every
         * surface states as «not reported», never as zero. Only the fields TikTok NAMED are dropped,
         * never the delivery figures, and only a bounded number of times — any other refusal is
         * thrown as it was, so a real failure still reads as one.
         */
        $reported = $this->report($tokens, $adAccountId, $from, $to, 'AUCTION_CAMPAIGN', 'campaign_id');

        $this->countRawInsightRows(count($reported));

        // TIKTOK-CURRENCY-001 — every row says what unit its money is in; the report itself does not.
        $currency = $this->accountCurrency($tokens, $adAccountId);

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

            $mapped = array_filter([
                'currency' => $currency,
            ], static fn ($v) => $v !== null) + [
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

    /**
     * One BASIC report at one level, with the refusal-tolerant retry (TIKTOK-METRIC-REFUSAL-001).
     *
     * The metrics are asked for FROM the map, so a metric cannot be mapped and then never requested —
     * which reads downstream exactly like a platform that does not report it.
     *
     * @return list<array<string,mixed>>
     */
    private function report(OAuthTokens $tokens, string $adAccountId, string $from, string $to, string $level, string $idDimension): array
    {
        $fields = array_values(array_unique(self::METRICS));

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->readAll($tokens, 'report/integrated/get/', 'daily report', [
                    'advertiser_id' => $adAccountId,
                    'report_type' => 'BASIC',
                    'data_level' => $level,
                    // TikTok wants these as JSON strings inside the query, not repeated parameters.
                    'dimensions' => json_encode([$idDimension, 'stat_time_day'], JSON_THROW_ON_ERROR),
                    'metrics' => json_encode($fields, JSON_THROW_ON_ERROR),
                    'start_date' => $from,
                    'end_date' => $to,
                ]);
            } catch (RuntimeException $e) {
                $refused = self::refusedFieldsIn($e->getMessage());
                $droppable = array_values(array_diff(array_intersect($refused, $fields), self::DELIVERY));
                if ($attempt >= 1 || $droppable === []) {
                    throw $e;
                }
                $fields = array_values(array_diff($fields, $droppable));
                $this->refusedMetrics = array_values(array_unique([...$this->refusedMetrics, ...$droppable]));
                Log::warning('TikTok refused report metrics; the report was asked again without them.', [
                    'advertiser_id' => $adAccountId,
                    'refused' => $droppable,
                ]);
            }
        }
    }

    public function syncCreativeInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        try {
            return SyncResult::of($this->fetchCreativeInsights($this->tokens(), $adAccountId, $from, $to));
        } catch (\Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    /**
     * TIKTOK-CONTENT-001 — daily figures per CREATIVE, from the ad-level report.
     *
     * TikTok reports by ad (`data_level=AUCTION_AD`, `ad_id`); a creative is the video, image or
     * Spark post the ad carries, and several ads can run one video. So each ad's day is attributed to
     * its creative and the additive figures are summed per creative and day — the provider's creative
     * id travels in `campaign_id`, as `ReportsCreativeInsights` defines the row. Reach counts unique
     * people per ad and is not summed: a creative run by two ads states no reach rather than a wrong
     * one. Without this the account wrote no creative figures at all, and every TikTok card read
     * «no data».
     *
     * @return list<array<string,mixed>>
     */
    public function fetchCreativeInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $creativeOfAd = [];
        foreach ($this->readAll($tokens, 'ad/get/', 'ads', ['advertiser_id' => $adAccountId]) as $a) {
            $creative = $this->creativeOf($a);
            if ($creative !== null && isset($a['ad_id'])) {
                $creativeOfAd[(string) $a['ad_id']] = (string) $creative['external_id'];
            }
        }

        $reported = $this->report($tokens, $adAccountId, $from, $to, 'AUCTION_AD', 'ad_id');
        $currency = $this->accountCurrency($tokens, $adAccountId);

        $rows = [];
        $ads = [];
        foreach ($reported as $row) {
            $dims = (array) ($row['dimensions'] ?? []);
            $metrics = (array) ($row['metrics'] ?? []);
            $creative = $creativeOfAd[(string) ($dims['ad_id'] ?? '')] ?? null;
            if ($creative === null) {
                continue;
            }
            $date = substr((string) ($dims['stat_time_day'] ?? $from), 0, 10);
            $key = $creative.'|'.$date;
            $rows[$key] ??= array_filter(['currency' => $currency], static fn ($v) => $v !== null) + ['campaign_id' => $creative, 'date' => $date];
            $ads[$key] = ($ads[$key] ?? 0) + 1;

            foreach (self::METRICS as $canonical => $field) {
                if (! array_key_exists($field, $metrics) || $metrics[$field] === null) {
                    continue;
                }
                $rows[$key][$canonical] = ($rows[$key][$canonical] ?? 0.0) + (float) $metrics[$field];
            }
        }

        foreach ($ads as $key => $count) {
            if ($count > 1) {
                unset($rows[$key]['reach']);
            }
        }

        return array_values($rows);
    }
}
