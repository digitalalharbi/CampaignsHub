<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Reporting\ReportingWindow;
use App\Domains\Integrations\ValueObjects\SyncResult;

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
final class SnapchatConnector extends ApiAdvertisingConnector implements ReportsCreativeInsights
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
    /** Snapchat takes up to 2,000 media ids per batch; chunked so a larger account is never truncated. */
    private const MEDIA_PER_REQUEST = 2000;

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
                /*
                 * SNAP-CREATIVE-ASSETS-001 — the asset lives behind a second call, and this is its key.
                 *
                 * `top_snap_media_id` is «the Media ID of the top snap (image/video)». The creative
                 * body carries the id and never the file, which is why this used to store nothing
                 * and every Snapchat card read «لا تتيح هذه المنصة أصل المحتوى» — a statement about
                 * the PLATFORM that was false. Snapchat exposes the asset perfectly well; we had
                 * not asked.
                 */
                'media_id' => isset($c['top_snap_media_id']) ? (string) $c['top_snap_media_id'] : null,
            ], static fn ($v) => $v !== null);
        }

        return $this->withMedia($tokens, $creatives);
    }

    /**
     * SNAP-CREATIVE-ASSETS-001 — resolve every creative's media in ONE call, not one call each.
     *
     * ## Why the batch endpoint and not `GET /media/{id}`
     *
     * The live account holds 1,451 creatives. Asking per creative is 1,451 round trips against an
     * API that rate-limits, on a job that already had to have its timeout raised to fifteen minutes
     * — it would turn a working structure sweep into a throttled one. `get_media_by_ids` takes up
     * to 2,000 ids per request, so the whole account is one call, with chunking left in place
     * because an account may exceed that and a silently truncated list is the worst outcome.
     *
     * ## What is stored, and what is deliberately not
     *
     * `download_link` is the file. It is a signed URL, so it is stored with the expiry the platform
     * states and `CreativePresenter` already refuses to render an expired one — «انتهت صلاحية رابط
     * المنصة» rather than a broken image.
     *
     * A link carrying a credential in its QUERY is never stored at all. The presenter has a
     * `withheld` state for exactly this, and the rule is that a token must not reach the browser or
     * the logs. Snapchat signs with an opaque signature rather than an access token, so this is a
     * guard rather than an expectation — but a guard is what makes it safe to be wrong about.
     *
     * @param  array<string,array<string,mixed>>  $creatives
     * @return array<string,array<string,mixed>>
     */
    private function withMedia(OAuthTokens $tokens, array $creatives): array
    {
        $mediaIds = array_values(array_unique(array_filter(
            array_map(static fn (array $c): ?string => $c['media_id'] ?? null, $creatives),
        )));

        if ($mediaIds === []) {
            return $creatives;
        }

        $media = [];

        foreach (array_chunk($mediaIds, self::MEDIA_PER_REQUEST) as $chunk) {
            try {
                $body = $this->read(
                    $this->api($tokens)->post($this->url('adaccounts/get_media_by_ids'), ['media_ids' => $chunk]),
                    'media',
                );
            } catch (\Throwable) {
                /*
                 * The asset is an enrichment. A creative with no picture is still a creative, and
                 * failing the whole structure sweep because a media lookup was throttled would cost
                 * the campaigns, ad squads and ads that came with it.
                 */
                continue;
            }

            foreach ((array) ($body['media'] ?? []) as $wrapper) {
                $m = (array) (((array) $wrapper)['media'] ?? []);

                if (($m['id'] ?? null) === null) {
                    continue;
                }

                $media[(string) $m['id']] = $m;
            }
        }

        foreach ($creatives as $id => $creative) {
            $m = $media[$creative['media_id'] ?? ''] ?? null;

            if ($m === null) {
                continue;
            }

            $link = $this->safeAssetUrl($m['download_link'] ?? null);
            $isVideo = strtoupper((string) ($m['type'] ?? '')) === 'VIDEO';

            $creatives[$id] = array_filter([
                ...$creative,
                // A video's file is the video; an image's file is the image. Storing a video URL in
                // the image column is what makes a card try to render an MP4 as a picture.
                'asset_url' => $isVideo ? null : $link,
                'video_url' => $isVideo ? $link : null,
                'source_updated_at' => isset($m['updated_at']) ? (string) $m['updated_at'] : null,
            ], static fn ($v) => $v !== null);
        }

        return $creatives;
    }

    /**
     * A URL is stored only when it carries no credential.
     *
     * `CreativePresenter` has a `withheld` state for a preview link that bears one, and the standing
     * rule is that a provider token must never reach the browser or a log line. Returning null here
     * is what makes that state reachable instead of theoretical.
     */
    private function safeAssetUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));

        foreach (['access_token', 'oauth', 'bearer', 'apikey', 'api_key'] as $secret) {
            if (str_contains($query, $secret)) {
                return null;
            }
        }

        return $url;
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

    /**
     * CANONICAL-METRIC-CATALOG-001 — the full set Snapchat exposes, for the ad-squad and ad grains.
     *
     * Deliberately separate from {@see self::METRICS} rather than an extension of it. That map feeds
     * `daily_metrics`, whose catalogue decides which keys survive; adding to it would push keys
     * through a pipeline that filters them out anyway, and any mistake there would land on the
     * campaign totals every surface already reads. This map feeds `entity_daily_metrics` only.
     *
     * Field names verified against the current Marketing API (developers.snap.com, August 2026).
     * Nothing here is guessed: a metric Snapchat does not expose is absent from this table rather
     * than present and empty, which is the difference between UNSUPPORTED and NOT_REPORTED.
     */
    private const ENTITY_METRICS = [
        // delivery
        'spend' => 'spend',
        'impressions' => 'impressions',
        'reach' => 'uniques',
        'frequency' => 'frequency',
        // traffic — Snapchat calls a click a swipe
        'clicks' => 'swipes',
        'landing_page_views' => 'conversion_page_views',
        // video. `screen_time_millis` is MILLISECONDS and is converted at this edge, once.
        'video_views' => 'video_views',
        'video_views_2s' => 'video_views_time_based',
        'video_views_5s' => 'video_views_5s',
        'video_views_15s' => 'video_views_15s',
        'video_p25' => 'quartile_1',
        'video_p50' => 'quartile_2',
        'video_p75' => 'quartile_3',
        'video_p100' => 'view_completion',
        'video_watch_seconds' => 'screen_time_millis',
        // results
        'conversions' => 'conversion_purchases',
        'purchases' => 'conversion_purchases',
        'revenue' => 'conversion_purchases_value',
        'add_to_cart' => 'conversion_add_cart',
        'checkout' => 'conversion_start_checkout',
        'leads' => 'native_leads',
        'sign_ups' => 'conversion_sign_ups',
        'installs' => 'total_installs',
        'app_opens' => 'conversion_app_opens',
        'page_views' => 'conversion_page_views',
    ];

    /** Reported in milliseconds; the product reports watch time in seconds. Divided once, here. */
    private const MILLIS = ['video_watch_seconds'];

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
     * SNAP-CREATIVE-METRICS-001 — the same stats call, asked at the CREATIVE level.
     *
     * `fetchInsights()` above is untouched: it feeds `daily_metrics`, whose natural key is
     * `(account, campaign, metric, date, window)` and which has no column for a creative. This is a
     * separate read with a separate destination — `creative_daily_metrics`, keyed
     * `(creative_id, metric_date)` — so campaign totals cannot be disturbed by it.
     *
     * The window is chunked identically, for the identical reason: a provider that caps a DAY range
     * refuses the whole request rather than truncating it, and a first sync asks for thirty days.
     *
     * Rows come back in the same canonical shape `pointToRow()` produces, except that the id in
     * `campaign_id` is the PROVIDER'S CREATIVE id. The caller resolves it against
     * `external_creatives.external_creative_id`; a creative we have not discovered yet is the
     * caller's decision to skip, not this adapter's to invent.
     */
    public function syncCreativeInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        /*
         * No `refusal()` pre-check — it is private to the base class, and duplicating it here would
         * put a second copy of the credential rule in the codebase. `tokens()` throws when there are
         * none, and the catch below turns that into the same failed result the pre-check would have
         * produced. One rule, one place.
         */
        try {
            return SyncResult::of($this->fetchCreativeInsights($this->tokens(), $adAccountId, $from, $to));
        } catch (\Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public function fetchCreativeInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $window = ReportingWindow::localDays($this->accountTimezone($adAccountId), $from, $to);

        $rows = [];

        foreach ($window->chunked($this->maxDaysPerRequest()) as $chunk) {
            foreach ($this->fetchWindow($adAccountId, $tokens, $chunk, 'creative') as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * METRICS-BACKBONE-001 — stats for the two rungs the product could not measure.
     *
     * ## Why this is per-parent and not one account call
     *
     * The creative grain works from `adaccounts/{id}/stats?breakdown=creative` — one call for the
     * whole account. The API offers no such shortcut here: `breakdown=adsquad` lives on the CAMPAIGN
     * stats endpoint and `breakdown=ad` on the AD SQUAD endpoint, so each parent must be asked for
     * its own children. On the live account that is 89 campaigns, then 187 ad squads.
     *
     * That cost is why this is its own method taking a parent list, rather than another `$breakdown`
     * value on `fetchWindow()`: the caller decides how much of the account to sweep.
     *
     * @param  list<string>  $parentIds  campaign ids for the ad-squad grain, ad-squad ids for the ad grain
     * @return list<array<string,mixed>>
     */
    /**
     * The caller-facing entry point, mirroring `syncCreativeInsights()`.
     *
     * Tokens are resolved INSIDE the connector — `tokens()` is protected on the base class, and one
     * rule about credentials living in one place is worth more than a shorter signature.
     *
     * @param  list<string>  $parentIds
     */
    public function syncEntityInsights(
        string $adAccountId,
        string $parentPath,
        string $breakdown,
        array $parentIds,
        string $from,
        string $to,
    ): SyncResult {
        try {
            return SyncResult::of($this->fetchEntityInsights(
                $this->tokens(), $adAccountId, $parentPath, $breakdown, $parentIds, $from, $to,
            ));
        } catch (\Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    public function fetchEntityInsights(
        OAuthTokens $tokens,
        string $adAccountId,
        string $parentPath,
        string $breakdown,
        array $parentIds,
        string $from,
        string $to,
    ): array {
        $window = ReportingWindow::localDays($this->accountTimezone($adAccountId), $from, $to);
        $rows = [];

        foreach ($parentIds as $parentId) {
            foreach ($window->chunked($this->maxDaysPerRequest()) as $chunk) {
                /*
                 * One parent's failure costs that parent's children and nothing else. A sweep of 187
                 * ad squads that abandoned everything because the ninth was throttled would report
                 * far less than it knows, and the result would look like an account with no ads
                 * rather than one call that needs retrying.
                 */
                try {
                    foreach ($this->fetchEntityWindow($parentPath, $parentId, $tokens, $chunk, $breakdown) as $row) {
                        $rows[] = $row;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function fetchEntityWindow(
        string $parentPath,
        string $parentId,
        OAuthTokens $tokens,
        ReportingWindow $window,
        string $breakdown,
    ): array {
        $url = $this->url("{$parentPath}/{$parentId}/stats").'?'.http_build_query([
            'granularity' => 'DAY',
            'breakdown' => $breakdown,
            'fields' => implode(',', array_values(array_unique(self::ENTITY_METRICS))),
            'start_time' => $window->startIso(),
            'end_time' => $window->endIso(),
            'limit' => self::STATS_PAGE_SIZE,
        ]);

        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            $body = $this->read($this->api($tokens)->get($url), "{$breakdown} stats");

            foreach ((array) ($body['timeseries_stats'] ?? []) as $wrapper) {
                $series = (array) (((array) $wrapper)['timeseries_stat'] ?? []);

                foreach ($this->entitySeries($series, $breakdown) as $entityId => $points) {
                    foreach ($points as $point) {
                        $rows[] = $this->entityPointToRow($entityId, (array) $point);
                    }
                }
            }

            $next = $body['paging']['next_link'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $rows;
    }

    /**
     * The per-entity series inside one response.
     *
     * @param  array<string,mixed>  $series
     * @return array<string, list<array<string,mixed>>>
     */
    private function entitySeries(array $series, string $breakdown): array
    {
        $out = [];

        foreach ((array) ($series['breakdown_stats'][$breakdown] ?? []) as $entry) {
            $entry = (array) $entry;
            $id = (string) ($entry['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $out[$id] = (array) ($entry['timeseries'] ?? []);
        }

        return $out;
    }

    /**
     * One point, in the canonical shape the upsert expects.
     *
     * @param  array<string,mixed>  $point
     * @return array<string,mixed>
     */
    private function entityPointToRow(string $entityId, array $point): array
    {
        /** @var array<string,mixed> $stats */
        $stats = (array) ($point['stats'] ?? []);

        $row = [
            'entity_id' => $entityId,
            'date' => substr((string) ($point['start_time'] ?? ''), 0, 10),
        ];

        foreach (self::ENTITY_METRICS as $canonical => $field) {
            // ABSENT, not zero: a metric this account does not report arrives as a missing key so
            // the column stays null and the reader says «not reported» rather than «none».
            if (! array_key_exists($field, $stats)) {
                continue;
            }

            $value = (float) $stats[$field];

            $row[$canonical] = match (true) {
                in_array($canonical, self::MONEY, true) => $value / self::MICRO,
                in_array($canonical, self::MILLIS, true) => $value / 1000,
                default => $value,
            };
        }

        return $row;
    }

    /**
     * One provider-valid window's worth of stats, read to its last page.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchWindow(string $adAccountId, OAuthTokens $tokens, ReportingWindow $window, string $breakdown = 'campaign'): array
    {
        $url = $this->url("adaccounts/{$adAccountId}/stats").'?'.http_build_query([
            'granularity' => 'DAY',
            /*
             * SNAP-CREATIVE-METRICS-001 — the level is a parameter, not a constant.
             *
             * It was hardcoded to `campaign`, so the only metrics this product ever held were
             * campaign totals. The content library listed 1,451 real creatives with «لا توجد بيانات»
             * under every one of them, and that was accurate: not a single creative-level row
             * existed to show. `creative_daily_metrics` has been in the schema since
             * `2026_07_27_120000`, complete and empty, because nobody ever asked Snapchat for it.
             */
            'breakdown' => $breakdown,
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
                $series = (array) (((array) $wrapper)['timeseries_stat'] ?? []);

                foreach ($this->campaignSeries($series, $breakdown) as $campaignId => $points) {
                    /*
                     * Counted for the CAMPAIGN level only — SYNC-TRUTH-004.
                     *
                     * The four counters describe the metrics run, whose parsed and mapped figures are
                     * campaign rows. Counting the creative pass here too made `provider_raw_rows` 4
                     * against `parsed_rows` 2 on a run that had parsed everything it fetched, so a
                     * healthy sweep reported itself as having dropped half its rows. A diagnostic
                     * that lies about loss is worse than no diagnostic.
                     */
                    if ($breakdown === 'campaign') {
                        $this->countRawInsightRows(count($points));
                    }

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
    private function campaignSeries(array $series, string $level = 'campaign'): array
    {
        /** @var array<string,mixed> $breakdown */
        $breakdown = (array) ($series['breakdown_stats'] ?? []);

        if (isset($breakdown[$level]) && is_array($breakdown[$level])) {
            $byCampaign = [];

            foreach ($breakdown[$level] as $entry) {
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
