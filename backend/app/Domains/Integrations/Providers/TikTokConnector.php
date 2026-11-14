<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Support\AssetExpiry;
use App\Domains\Integrations\Support\TikTokCampaignStatus;
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
final class TikTokConnector extends ApiAdvertisingConnector implements ReportsAudienceBreakdowns, ReportsCreativeInsights
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

        // The loop only runs off its end when the provider still had pages.
        if ($page > self::MAX_PAGES) {
            $this->noteTruncatedRead($what);
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

            // TIKTOK-CAMPAIGN-STATUS-001 — TikTok's own values, mapped; one it has not documented is logged by name, never guessed.
            $state = TikTokCampaignStatus::resolve(
                isset($c['operation_status']) ? (string) $c['operation_status'] : null,
                isset($c['secondary_status']) ? (string) $c['secondary_status'] : null,
            );
            if ($state['unmapped'] !== null) {
                Log::warning('TikTok campaign status not documented; kept as unknown where nothing else decides it', [
                    'advertiser_id' => $adAccountId, 'campaign_id' => (string) $c['campaign_id'], 'unmapped' => $state['unmapped'], 'status' => $state['status'],
                ]);
            }

            if ($state['signal'] === 'account_suspended') {
                // Not a pause somebody chose: TikTok suspended the advertiser account. Named, so it is never read as a quiet «paused».
                Log::warning('TikTok advertiser account suspended (ADVERTISER_ACCOUNT_PUNISH); its campaigns are not delivering', [
                    'advertiser_id' => $adAccountId, 'campaign_id' => (string) $c['campaign_id'],
                ]);
            }

            $campaigns[] = [
                'external_id' => (string) $c['campaign_id'],
                'name' => (string) ($c['campaign_name'] ?? $c['campaign_id']),
                'status' => $state['status'],
                // Kept with the row (it lands in `external_campaigns.raw`): budget_exhausted / account_suspended, else null.
                'delivery_signal' => $state['signal'],
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
    /**
     * Spark posters asked for in one sweep — a call each, so bounded.
     *
     * CREATIVE-COVERS-COVERAGE-001 — 400 was smaller than the live account: Production 2026-11-07 held 655
     * Spark creatives, so the posts past the 400th were never asked, recorded «TikTok was asked and returned
     * nothing» (`spark_post_info_missing`, 121 creatives) and lost the links a previous sweep had written. The
     * cap now sits above the estate, every call still goes through the provider's rate budget, and a post past
     * it says so (`spark_not_asked`) instead of blaming TikTok.
     */
    private const SPARK_POSTER_CAP = 1500;

    /** Identities of the advertiser tried for one post whose own identity is unknown or did not answer. */
    private const SPARK_FALLBACK_IDENTITIES = 4;

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

    /**
     * CREATIVE-PREVIEWS-001 — fresh signed links for named creatives, for the cover cache.
     *
     * The cover cache meets a TikTok link that has lapsed (an image link lives an hour, a video cover six)
     * whenever the structure sweep that wrote it is older than that. Rather than wait for the next sweep,
     * it asks the same two Asset Library reads the sweep uses — `GET /file/video/ad/info/` (`video_ids`,
     * answering `video_cover_url`, `preview_url`, `preview_url_expire_time`) and `GET /file/image/ad/info/`
     * (`image_ids`, answering `image_url`) — through the same budgeted client, for these ids only.
     *
     * CREATIVE-COVERS-COVERAGE-001 — a Spark post too. Its links live hours, and this used to leave it to the
     * next sweep because a creative row did not carry the identity that owns the post. The sweep now keeps that
     * identity on the creative (`raw.identity_*`), so the post is asked here with its full address, and with the
     * advertiser's other identities when that one does not answer.
     *
     * @param  list<array{external_id:string, media:?string, identity?:array<string,string>}>  $creatives
     * @return array<string, array<string,mixed>> external id => the fresh link columns
     */
    public function renewCreativeMedia(string $adAccountId, array $creatives): array
    {
        $ads = [];
        foreach ($creatives as $c) {
            if (in_array($c['media'] ?? null, ['video', 'image'], true)) {
                $ads[] = ['external_id' => null, 'creative' => ['external_id' => (string) $c['external_id'], 'raw' => ['media' => $c['media']]]];
            } elseif (($c['media'] ?? null) === 'spark') {
                // CREATIVE-COVERS-COVERAGE-001 — the post's identity, kept on the creative by the sweep, is the ad's.
                $ads[] = ['external_id' => null, 'raw' => (array) ($c['identity'] ?? []), 'creative' => ['external_id' => (string) $c['external_id'], 'raw' => ['media' => 'spark']]];
            }
        }
        if ($ads === []) {
            return [];
        }

        $renewed = [];
        foreach ($this->withMedia($this->tokens(), $adAccountId, $ads) as $ad) {
            $renewed[(string) $ad['creative']['external_id']] = array_intersect_key(
                (array) $ad['creative'],
                ['asset_url' => true, 'video_url' => true, 'thumbnail_url' => true, 'asset_expires_at' => true, 'width' => true, 'height' => true],
            );
        }

        return $renewed;
    }

    /** An identifier TikTok may send as a string or as a number, as a non-empty string — or null. */
    private function scalarId(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
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
            if (($creative['raw']['media'] ?? null) === 'spark') {
                /*
                 * CONTENT-PREVIEW-COVERAGE-001 — ONE POST, MANY ADS: the identity comes from an ad that HAS one.
                 *
                 * A creative here is the organic post, so several ads share it, and the map was keyed by the post
                 * with `! isset(...)`: the FIRST ad seen decided, and an ad with no identity wrote an empty entry
                 * that every later ad — one that did carry the identity — was then refused. The post was recorded
                 * as «identity unknown» (`spark_identity_unknown`, 154 live creatives) although TikTok had named
                 * the identity on a sibling ad. An id TikTok sends as a number was dropped the same way, by a
                 * string-only filter. A usable identity now replaces an unusable one; a usable one is never replaced.
                 */
                $postId = (string) $creative['external_id'];
                $identity = array_filter([
                    'identity_type' => $this->scalarId($raw['identity_type'] ?? null),
                    'identity_id' => $this->scalarId($raw['identity_id'] ?? null),
                    'identity_authorized_bc_id' => $this->scalarId($raw['identity_authorized_bc_id'] ?? null),
                ], static fn ($v) => $v !== null);
                $complete = static fn (array $i): bool => isset($i['identity_type'], $i['identity_id']);
                if (! isset($sparks[$postId]) || (! $complete($sparks[$postId]) && $complete($identity))) {
                    $sparks[$postId] = $identity;
                }
            }
        }

        $videos = [];
        $images = [];
        $posts = [];
        $notAsked = [];
        $ownerBc = null;
        /*
         * CREATIVE-COVERS-COVERAGE-001 — a post whose ad named no identity, or whose identity did not answer, is
         * asked with the advertiser's own identities (`identity/get/`), a few at most. Production 2026-11-07: 146
         * Spark creatives were «identity unknown» and 121 «asked, nothing returned» — the Owner says the posts are
         * live. TikTok answers a post only under the identity that owns it, so the right one answers and the
         * others are refused; the identity that answered is kept on the creative for the cover renewal.
         */
        $fallback = null;
        $asked = 0;
        foreach ($sparks as $itemId => $identity) {
            $itemId = (string) $itemId;
            if ($asked >= self::SPARK_POSTER_CAP) {
                $notAsked[$itemId] = true;

                continue;
            }
            $tryList = isset($identity['identity_type'], $identity['identity_id']) ? [$identity] : [];
            $expanded = false;
            $media = null;
            for ($i = 0; $media === null; $i++) {
                if ($i === count($tryList)) {
                    if ($expanded) {
                        break;
                    }
                    $expanded = true;
                    $fallback ??= $this->advertiserIdentities($tokens, $adAccountId, $ownerBc);
                    $others = array_values(array_filter($fallback, static fn (array $o): bool => ! in_array($o, $tryList, true)));
                    array_push($tryList, ...array_slice($others, 0, self::SPARK_FALLBACK_IDENTITIES));
                    if ($i === count($tryList)) {
                        break;
                    }
                }
                $candidate = $tryList[$i];
                $asked++;
                $media = $this->sparkPost($tokens, $adAccountId, $itemId, $candidate, $ownerBc);
                if ($media !== null) {
                    $sparks[$itemId] = $candidate;
                    $posts[$itemId] = $media;
                    // The identity that answered is the likeliest owner of the next post: ask it first.
                    if ($fallback !== null && ($at = array_search($candidate, $fallback, true)) !== false) {
                        unset($fallback[$at]);
                        array_unshift($fallback, $candidate);
                    }
                }
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
                $poster = $v['poster'];
                $file = $v['file'];
                /*
                 * CONTENT-PREVIEW-COVERAGE-001 — the post's own frame size, length and link expiry are kept too.
                 *
                 * `video_info` states `width`, `height` and `duration` (measured 2026-10-18), and this arm stored the
                 * two links only: every Spark creative reached the card with no shape. Its signed links carry their
                 * expiry in the URL, which `AssetExpiry` reads; the earlier of the two links decides.
                 *
                 * CREATIVE-COVERS-COVERAGE-001 — a photo (carousel) post has no film: its first picture is both the
                 * still and the content.
                 */
                $expiries = array_filter([AssetExpiry::fromUrl($file), AssetExpiry::fromUrl($poster)]);
                $creative += array_filter([
                    'thumbnail_url' => $poster,
                    'video_url' => $file,
                    'asset_url' => $file === null ? $poster : null,
                    'width' => $v['width'],
                    'height' => $v['height'],
                    'duration_seconds' => $v['duration'],
                    'asset_expires_at' => $expiries === [] ? null : min($expiries)->toIso8601String(),
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
                    'spark' => match (true) {
                        isset($notAsked[$id]) => 'spark_not_asked',
                        isset($sparks[$id]['identity_type'], $sparks[$id]['identity_id']) => 'spark_post_info_missing',
                        default => 'spark_identity_unknown',
                    },
                    default => 'no_asset_declared',
                };
                $creative['raw'] = ((array) ($creative['raw'] ?? [])) + ['media_reason' => $reason];
                Log::info('TikTok creative has no displayable asset.', ['ad' => $ad['external_id'] ?? null, 'creative' => $id, 'media' => $media, 'reason' => $reason]);
            }
            /*
             * CREATIVE-COVERS-COVERAGE-001 — the identity that owns a Spark post rides on its creative, so the cover
             * renewal can ask TikTok for the post again when its hours-long links have lapsed.
             */
            if ((((array) ($creative['raw'] ?? []))['media'] ?? null) === 'spark' && isset($sparks[$id]['identity_type'], $sparks[$id]['identity_id'])) {
                $creative['raw'] = [...(array) $creative['raw'], ...array_filter([
                    'identity_type' => $sparks[$id]['identity_type'],
                    'identity_id' => $sparks[$id]['identity_id'],
                    'identity_authorized_bc_id' => $sparks[$id]['identity_authorized_bc_id'] ?? null,
                ], static fn ($v) => $v !== null)];
            }
            $ads[$n]['creative'] = $creative;
        }

        return $ads;
    }

    /**
     * One Spark post through `identity/video/info/`, asked under one identity — its still, file and shape, or null.
     *
     * TIKTOK-SPARK-COVER-001 (evidence 2026-10-18) — a `BC_AUTH_TT` identity is a post authorised to a Business
     * Center and is answered only with that Business Center (`identity_authorized_bc_id`); the advertiser's
     * `owner_bc_id` is used when the identity does not carry its own. The answer is `data.video_detail.video_info`
     * (the legacy `data.video_info` is still read).
     *
     * @param  array<string,string>  $identity
     * @return array{poster: ?string, file: ?string, width: ?int, height: ?int, duration: ?float}|null
     */
    private function sparkPost(OAuthTokens $tokens, string $adAccountId, string $itemId, array $identity, mixed &$ownerBc): ?array
    {
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
        } catch (RuntimeException $e) {
            Log::warning('TikTok Spark post info could not be read under one identity.', ['reason' => $e->getMessage(), 'identity_type' => $identity['identity_type']]);

            return null;
        }

        return self::sparkMedia((array) ($body['data'] ?? []));
    }

    /**
     * The still and the file a post info answer names.
     *
     * A video post states `video_info` (measured 2026-10-18: bit_rate, duration, format, height, poster_url,
     * signature, size, url, width). A photo post (the Spark «carousel» posts — 26 live creatives with nothing
     * drawn) carries no film; its pictures sit in an image list beside it, so the first picture TikTok names is
     * its still. Only TikTok's own links are read — nothing is composed.
     *
     * @param  array<string,mixed>  $data
     * @return array{poster: ?string, file: ?string, width: ?int, height: ?int, duration: ?float}|null
     */
    public static function sparkMedia(array $data): ?array
    {
        $detail = (array) ($data['video_detail'] ?? $data);
        $info = (array) ($detail['video_info'] ?? $data['video_info'] ?? []);
        $first = static fn (array $from, array $keys): ?string => collect($keys)
            ->map(static fn (string $k) => $from[$k] ?? null)
            ->first(static fn ($u) => is_string($u) && str_starts_with($u, 'https://'));

        $poster = $first($info, ['poster_url', 'cover_image_url', 'cover_url', 'video_cover_url'])
            ?? $first($detail, ['poster_url', 'cover_url', 'cover_image_url', 'thumbnail_url']);
        $file = $first($info, ['preview_url', 'url', 'video_url', 'play_url']);

        if ($poster === null) {
            foreach (['image_info', 'image_infos', 'images', 'image_list', 'carousel_info', 'photo_info'] as $key) {
                if (isset($detail[$key]) && is_array($detail[$key]) && ($found = self::firstImageLink($detail[$key])) !== null) {
                    $poster = $found;
                    break;
                }
            }
        }

        if ($poster === null && $file === null) {
            return null;
        }

        return [
            'poster' => $poster,
            'file' => $file,
            'width' => is_numeric($info['width'] ?? null) ? (int) $info['width'] : null,
            'height' => is_numeric($info['height'] ?? null) ? (int) $info['height'] : null,
            'duration' => is_numeric($info['duration'] ?? null) ? (float) $info['duration'] : null,
        ];
    }

    /** The first https link under an image-ish key, depth-first, in the order TikTok listed them. */
    private static function firstImageLink(array $node, int $depth = 0): ?string
    {
        if ($depth > 4) {
            return null;
        }
        foreach ($node as $key => $value) {
            if (is_string($value) && str_starts_with($value, 'https://')
                && (is_int($key) || preg_match('/(image|url|cover|poster|thumb)/i', (string) $key) === 1)) {
                return $value;
            }
            if (is_array($value) && ($found = self::firstImageLink($value, $depth + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The advertiser's identities, from `identity/get/` — each type asked once per sweep, a refusal costing only that type.
     *
     * `BC_AUTH_TT` identities are listed under the Business Center that holds them, so that type is asked with the
     * advertiser's owner Business Center when one is known.
     *
     * @return list<array<string,string>>
     */
    private function advertiserIdentities(OAuthTokens $tokens, string $adAccountId, mixed &$ownerBc): array
    {
        $found = [];
        foreach (['BC_AUTH_TT', 'TT_USER', 'AUTH_CODE'] as $type) {
            $query = ['advertiser_id' => $adAccountId, 'identity_type' => $type, 'page_size' => 100];
            if ($type === 'BC_AUTH_TT') {
                $ownerBc ??= $this->ownerBusinessCenter($tokens, $adAccountId) ?? false;
                if ($ownerBc === false) {
                    continue;
                }
                $query['identity_authorized_bc_id'] = $ownerBc;
            }
            try {
                $body = $this->read($this->api($tokens)->get($this->url('identity/get/'), $query), 'identities');
            } catch (RuntimeException $e) {
                Log::info('TikTok identities of one type could not be listed.', ['identity_type' => $type, 'reason' => $e->getMessage()]);

                continue;
            }
            foreach ((array) ($body['data']['identity_list'] ?? []) as $row) {
                $id = is_array($row) ? $this->scalarId($row['identity_id'] ?? null) : null;
                if ($id === null) {
                    continue;
                }
                $found[] = array_filter([
                    'identity_type' => $this->scalarId($row['identity_type'] ?? null) ?? $type,
                    'identity_id' => $id,
                    'identity_authorized_bc_id' => $this->scalarId($row['identity_authorized_bc_id'] ?? null) ?? ($type === 'BC_AUTH_TT' && is_string($ownerBc) ? $ownerBc : null),
                ], static fn ($v) => $v !== null);
            }
        }

        return $found;
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

        /*
         * CONTENT-TEXT-CTA-001 — the ad's text is the creative's copy. TikTok's `GET /ad/get/` states it in `ad_text`
         * (and, for an automated creative, `ad_texts`), and the call to action in `call_to_action`. Production
         * 2026-11-13: `raw.ad_text` on 129 of 284 active creatives and `body` on none — it was kept, never written.
         */
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $body = $text($ad['ad_text'] ?? null) ?? $text(((array) ($ad['ad_texts'] ?? []))[0] ?? null);

        return array_filter([
            'external_id' => $id,
            'name' => (string) ($ad['ad_name'] ?? $id),
            'format' => $media === 'image' ? 'image' : 'video',
            'body' => $body,
            'cta' => $text($ad['call_to_action'] ?? null),
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

    /**
     * AUDIENCE-001 slice 2 — paid delivery by age, gender and province, per ad group and day.
     *
     * The endpoint is the one this connector already reads for every figure, `report/integrated/get/`, with
     * `report_type=AUDIENCE` and `data_level=AUCTION_ADGROUP`. The audience dimension names (`age`, `gender`,
     * `province_id`) beside `adgroup_id` and `stat_time_day` come from the TikTok API for Business reporting docs as
     * mirrored by connector documentation; the official page (business-api.tiktok.com/portal/docs) renders only in a
     * browser and could not be read from here, so a combination TikTok refuses is recorded as that dimension's
     * failure and stores nothing — never a guess. Delivery metrics only (spend, impressions, clicks): no conversion is
     * attributed to an age, a gender or a province. A province is stored as TikTok's id, verbatim: its name is not
     * resolved (the location-name endpoint could not be verified either).
     */
    private const AUDIENCE_DIMENSION = ['age' => 'age', 'gender' => 'gender', 'region' => 'province_id'];

    public function audienceDimensions(): array
    {
        return array_keys(self::AUDIENCE_DIMENSION);
    }

    public function audienceMinimumImpressions(): int
    {
        return 1;
    }

    public function audienceSourceField(string $dimension): string
    {
        return 'tiktok:report/integrated/get report_type=AUDIENCE data_level=AUCTION_ADGROUP dimension='.(self::AUDIENCE_DIMENSION[$dimension] ?? $dimension);
    }

    public function audienceBreakdown(string $adAccountId, string $dimension, string $from, string $to, array $delivering = []): SyncResult
    {
        $tiktok = self::AUDIENCE_DIMENSION[$dimension] ?? null;
        if ($tiktok === null) {
            return SyncResult::failed("TikTok audience dimension «{$dimension}» is not asked for by this product.");
        }

        try {
            $reported = $this->readAll($this->tokens(), 'report/integrated/get/', "{$dimension} audience report", [
                'advertiser_id' => $adAccountId,
                'report_type' => 'AUDIENCE',
                'data_level' => 'AUCTION_ADGROUP',
                'dimensions' => json_encode(['adgroup_id', 'stat_time_day', $tiktok], JSON_THROW_ON_ERROR),
                'metrics' => json_encode(['spend', 'impressions', 'clicks'], JSON_THROW_ON_ERROR),
                'start_date' => $from,
                'end_date' => $to,
            ]);
        } catch (\Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }

        return SyncResult::of(self::mapAudienceRows($reported, $tiktok));
    }

    /**
     * @param  list<array<string, mixed>>  $reported
     * @return list<array<string, mixed>>
     */
    public static function mapAudienceRows(array $reported, string $tiktokDimension): array
    {
        $rows = [];
        foreach ($reported as $r) {
            $dims = (array) ($r['dimensions'] ?? []);
            $metrics = (array) ($r['metrics'] ?? []);
            $entity = (string) ($dims['adgroup_id'] ?? '');
            $date = substr((string) ($dims['stat_time_day'] ?? ''), 0, 10);
            $value = trim((string) ($dims[$tiktokDimension] ?? ''));
            if ($entity === '' || $date === '' || $value === '') {
                continue;
            }
            $row = ['entity_id' => $entity, 'campaign_id' => null, 'date' => $date, 'value' => $value];
            foreach (['impressions', 'clicks', 'spend'] as $m) {
                if (isset($metrics[$m]) && is_numeric($metrics[$m])) {
                    $row[$m] = (float) $metrics[$m];
                }
            }
            $rows[] = $row;
        }

        return $rows;
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
