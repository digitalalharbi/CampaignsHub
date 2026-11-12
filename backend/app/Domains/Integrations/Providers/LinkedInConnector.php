<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\ValueObjects\SyncResult;
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
final class LinkedInConnector extends ApiAdvertisingConnector implements ReportsCreativeInsights, ReportsPeriodReach
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

        // The loop only runs off its end when the last page was full.
        if ($page === self::MAX_PAGES) {
            $this->noteTruncatedRead($what);
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
                /*
                 * LinkedIn does not report a timezone on an ad account, and this says so on purpose.
                 *
                 * Production shows `timezone=NOT CAPTURED` on all eleven discovered accounts, beside
                 * Snapchat and Meta reporting `Asia/Riyadh`. That asymmetry reads like an omission
                 * and is not one: `adAccounts` carries no timezone field to read.
                 *
                 * Left null rather than defaulted to the tenant's zone, which is the tempting fix and
                 * the wrong one: a day boundary invented here would silently re-bucket every daily
                 * metric onto dates the platform never agreed to, and the figures would look right.
                 * A missing timezone is a fact about LinkedIn; a guessed one is a fact about us.
                 */
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
        $creatives = $this->readAll($tokens, "adAccounts/{$adAccountId}/creatives", 'creatives', ['q' => 'criteria']);

        /*
         * X-LINKEDIN-CONTENT-001 — the sponsored post behind each creative, read where the grant allows it.
         *
         * A Sponsored Content creative names its post in `content.reference` (`urn:li:share:…` / `urn:li:ugcPost:…`). The
         * post's words and media come from the Posts API, which LinkedIn gives only to `r_organization_social`, and its
         * picture's file from the Images / Videos APIs, which need `rw_ads`. Both are asked; a refusal is recorded per
         * creative as the reason, never as a made-up picture.
         */
        $references = [];
        foreach ($creatives as $c) {
            $ref = $c['content']['reference'] ?? null;
            if (is_string($ref) && (str_starts_with($ref, 'urn:li:share:') || str_starts_with($ref, 'urn:li:ugcPost:'))) {
                $references[] = $ref;
            }
        }

        [$posts, $postReason] = $this->batchGet($tokens, 'posts', array_values(array_unique($references)), 'linkedin_post_scope_missing');

        $mediaUrns = ['images' => [], 'videos' => []];
        foreach ($posts as $post) {
            $urn = $this->mediaUrnOf($post);
            if ($urn !== null) {
                $mediaUrns[str_starts_with($urn, 'urn:li:video:') ? 'videos' : 'images'][] = $urn;
            }
        }

        [$images, $imageReason] = $this->batchGet($tokens, 'images', array_values(array_unique($mediaUrns['images'])), 'linkedin_media_scope_missing');
        [$videos, $videoReason] = $this->batchGet($tokens, 'videos', array_values(array_unique($mediaUrns['videos'])), 'linkedin_media_scope_missing');

        $ads = [];

        foreach ($creatives as $c) {
            $id = $this->idFromUrn($c['id'] ?? null, 'sponsoredCreative');
            $campaignId = $this->idFromUrn($c['campaign'] ?? null, 'sponsoredCampaign');

            if ($id === null) {
                continue;
            }

            $ads[] = ['ad_set_external_id' => null] + array_filter([ // there is no such level on LinkedIn
                'external_id' => $id,
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
                'destination_url' => $this->textAd($c)['landing_page'] ?? null,
                // The creative IS the ad here, so a separate creative row would be the same thing
                // twice under two names. CREATIVE-SYNC-MEDIA-001: a Text Ad states its own words inside
                // the creative (readable with r_ads). X-LINKEDIN-CONTENT-001: every other creative is
                // still CONTENT and gets its row — it was `null` before, so a sponsored post never
                // reached the content library, the report or the live link at all.
                'creative' => $this->creativeFace($id, $c)
                    ?? $this->postFace($id, $c, $posts, $postReason, $images + $videos, $imageReason ?? $videoReason),
                'raw' => (array) $c,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * A Rest.li BATCH_GET (`?ids=List(…)`), refused or not.
     *
     * @param  list<string>  $urns
     * @return array{0: array<string, array<string, mixed>>, 1: string|null} the results by URN, and the reason when refused
     */
    private function batchGet(OAuthTokens $tokens, string $resource, array $urns, string $refusedReason): array
    {
        $out = [];
        $reason = null;

        foreach (array_chunk($urns, 50) as $chunk) {
            try {
                $response = $this->api($tokens)
                    ->withHeaders(['X-RestLi-Method' => 'BATCH_GET'])
                    ->get($this->url($resource).'?ids=List('.implode(',', array_map('rawurlencode', $chunk)).')');
            } catch (\Throwable) {
                $reason ??= 'linkedin_media_unreadable';

                continue;
            }

            if (in_array($response->status(), [401, 403], true)) {
                return [$out, $refusedReason]; // the grant, not the item: asking again will not change it
            }

            try {
                $body = $this->read($response, $resource);
            } catch (\Throwable) {
                $reason ??= 'linkedin_media_unreadable';

                continue;
            }

            foreach ((array) ($body['results'] ?? []) as $urn => $item) {
                if (is_array($item)) {
                    $out[(string) $urn] = $item;
                }
            }
        }

        return [$out, $reason];
    }

    /** The image or video URN a post shows: `content.media.id`, or an article's `thumbnail`. */
    private function mediaUrnOf(array $post): ?string
    {
        $content = (array) ($post['content'] ?? []);
        $urn = $content['media']['id'] ?? $content['article']['thumbnail'] ?? null;

        return is_string($urn) && (str_starts_with($urn, 'urn:li:image:') || str_starts_with($urn, 'urn:li:video:')) ? $urn : null;
    }

    /**
     * X-LINKEDIN-CONTENT-001 — a sponsored post's face: its words and picture when LinkedIn gave them, its identity and
     * the reason when it did not. The file link is LinkedIn's signed `downloadUrl`; `CoverCache` copies it to our own
     * storage (licdn is a copy-only host), so the card does not depend on a link that expires.
     *
     * @param  array<string, mixed>  $creative
     * @param  array<string, array<string, mixed>>  $posts
     * @param  array<string, array<string, mixed>>  $media
     * @return array<string, mixed>
     */
    private function postFace(string $id, array $creative, array $posts, ?string $postReason, array $media, ?string $mediaReason): array
    {
        $reference = is_string($creative['content']['reference'] ?? null) ? $creative['content']['reference'] : null;
        $post = $reference === null ? null : ($posts[$reference] ?? null);
        $mediaUrn = $post === null ? null : $this->mediaUrnOf($post);
        $file = $mediaUrn === null ? null : ($media[$mediaUrn] ?? null);
        $isVideo = $mediaUrn !== null && str_starts_with($mediaUrn, 'urn:li:video:');

        $https = static fn (mixed $u): ?string => is_string($u) && str_starts_with($u, 'https://') ? $u : null;
        $download = $file === null ? null : $https($file['downloadUrl'] ?? null);
        $still = $isVideo ? ($file === null ? null : $https($file['thumbnail'] ?? null)) : $download;
        $expires = is_numeric($file['downloadUrlExpiresAt'] ?? null)
            ? Carbon::createFromTimestampMs((int) $file['downloadUrlExpiresAt'])->toIso8601String()
            : null;

        $body = $post === null ? null : $this->littleText($post['commentary'] ?? null);
        $content = (array) ($post['content'] ?? []);
        $title = $content['media']['title'] ?? $content['article']['title'] ?? null;
        $headline = is_string($title) && trim($title) !== '' ? trim($title) : null;

        $reason = match (true) {
            $reference === null => 'linkedin_no_post_reference',
            $post === null => $postReason ?? 'linkedin_post_unreadable',
            $mediaUrn !== null && $still === null => $mediaReason ?? 'linkedin_media_unreadable',
            default => null,
        };

        return array_filter([
            'external_id' => $id,
            'name' => $headline ?? "Creative {$id}",
            'headline' => $headline,
            'body' => $body,
            'format' => match (true) {
                $mediaUrn !== null => $isVideo ? 'video' : 'image',
                $body !== null => 'text_card',
                default => null,
            },
            'thumbnail_url' => $still,
            'asset_url' => $isVideo ? null : $download,
            'video_url' => $isVideo ? $download : null,
            'asset_expires_at' => $download === null ? null : $expires,
            'destination_url' => is_string($content['article']['source'] ?? null) ? $content['article']['source'] : null,
            'raw' => array_filter([
                'reference' => $reference,
                'media_urn' => $mediaUrn,
                'media_reason' => $reason,
            ], static fn ($v) => $v !== null),
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /** LinkedIn's «little» text: `@[Name](urn)` → `Name`, `{hashtag|\#|word}` → `#word`. */
    private function littleText(mixed $text): ?string
    {
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $text = (string) preg_replace('/@\[([^\]]*)\]\([^)]*\)/u', '$1', $text);
        $text = (string) preg_replace('/\{hashtag\|\\?#\|([^}]*)\}/u', '#$1', $text);

        return trim($text) === '' ? null : trim($text);
    }

    /**
     * A Text Ad's words, from `content.textAd`.
     *
     * @param  array<string,mixed>  $creative
     * @return array{headline: ?string, description: ?string, landing_page: ?string}
     */
    private function textAd(array $creative): array
    {
        $ad = (array) ($creative['content']['textAd'] ?? []);
        $str = static fn (string $k): ?string => is_string($ad[$k] ?? null) && trim($ad[$k]) !== '' ? trim($ad[$k]) : null;

        return ['headline' => $str('headline'), 'description' => $str('description'), 'landing_page' => $str('landingPage')];
    }

    /**
     * CREATIVE-SYNC-MEDIA-001 — the creative's face where the creatives response itself carries one.
     *
     * Only a Text Ad does; everything else is null (never a made-up card), and the post reference is kept
     * in the retained payload so the gap can be named.
     *
     * @param  array<string,mixed>  $creative
     * @return array<string,mixed>|null
     */
    private function creativeFace(string $id, array $creative): ?array
    {
        $text = $this->textAd($creative);

        if ($text['headline'] === null && $text['description'] === null) {
            return null;
        }

        return array_filter([
            'external_id' => $id,
            'name' => $text['headline'],
            'headline' => $text['headline'],
            'body' => $text['description'],
            'format' => 'text_card',
            'destination_url' => $text['landing_page'],
        ], static fn ($v) => $v !== null);
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
         * LINKEDIN-RESULTS-001 — «Results» is the campaign's KEY RESULT, which LinkedIn chooses by the
         * campaign's objective. The canonical `conversions` is the platform's own results figure (as
         * TikTok's `conversion` is), and it was always `externalWebsiteConversions` — so an Engagement
         * campaign with 1,067 engagements and a Website Visits campaign with 960 landing-page clicks
         * both read «0 results» beside Campaign Manager's numbers. Each campaign's objective now names
         * the field its results are read from; the other figures are stored under their own keys.
         */
        $objectives = $this->objectivesByCampaign($tokens, $adAccountId);

        return $this->analytics($tokens, $adAccountId, $from, $to, 'CAMPAIGN', fn (mixed $pivot): ?string => $this->campaignIdFrom($pivot), $objectives);
    }

    /**
     * X-LINKEDIN-CONTENT-001 — daily figures per CREATIVE (`pivot=CREATIVE`), read with the same `r_ads_reporting`
     * grant as the campaign figures. Without it no LinkedIn creative ever had a figure or a `last_active_at`, and the
     * report, the live link and the content lists — which keep only content active in the period — dropped every one.
     * The provider's creative id travels in `campaign_id`, as `ReportsCreativeInsights` defines the row; the result is
     * read from the field the creative's own CAMPAIGN objective names, exactly as at the campaign grain.
     */
    public function syncCreativeInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        try {
            return SyncResult::of($this->fetchCreativeInsights($this->tokens(), $adAccountId, $from, $to));
        } catch (\Throwable $e) {
            return SyncResult::failed($e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    public function fetchCreativeInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $campaignObjectives = $this->objectivesByCampaign($tokens, $adAccountId);
        $objectives = [];

        foreach ($this->readAll($tokens, "adAccounts/{$adAccountId}/creatives", 'creatives', ['q' => 'criteria']) as $c) {
            $id = $this->idFromUrn($c['id'] ?? null, 'sponsoredCreative');
            $campaignId = $this->idFromUrn($c['campaign'] ?? null, 'sponsoredCampaign');

            if ($id !== null) {
                $objectives[$id] = $campaignId === null ? null : ($campaignObjectives[$campaignId] ?? null);
            }
        }

        return $this->analytics($tokens, $adAccountId, $from, $to, 'CREATIVE', fn (mixed $pivot): ?string => $this->creativeIdFrom($pivot), $objectives);
    }

    /** @return array<string, string|null> campaign id → LinkedIn objective */
    private function objectivesByCampaign(OAuthTokens $tokens, string $adAccountId): array
    {
        $objectives = [];
        foreach ($this->fetchCampaigns($tokens, $adAccountId) as $campaign) {
            $objectives[(string) $campaign['external_id']] = isset($campaign['objective']) ? strtoupper((string) $campaign['objective']) : null;
        }

        return $objectives;
    }

    /**
     * One `adAnalytics` read at one pivot, mapped to canonical daily rows keyed by the pivot's entity id.
     *
     * @param  callable(mixed): ?string  $entityOf
     * @param  array<string, string|null>  $objectives  entity id → objective
     * @return list<array<string, mixed>>
     */
    private function analytics(OAuthTokens $tokens, string $adAccountId, string $from, string $to, string $pivot, callable $entityOf, array $objectives): array
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
            ['videoViews', 'videoCompletions', 'totalEngagements', 'landingPageClicks', 'oneClickLeads'],
        );

        $reported = $this->readAll($tokens, 'adAnalytics', 'daily analytics', [
            'q' => 'analytics',
            'pivot' => $pivot,
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
            $campaignId = $entityOf($row['pivotValues'] ?? null);
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
                'conversions' => $this->resultOf($row, $objectives[$campaignId] ?? null),
                'leads' => isset($row['oneClickLeads']) ? (float) $row['oneClickLeads'] : null,
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
    /** LinkedIn objective → the analytics field Campaign Manager reports as that campaign's key result. */
    private const RESULT_FIELD = [
        'BRAND_AWARENESS' => 'impressions',
        'ENGAGEMENT' => 'totalEngagements',
        'WEBSITE_VISIT' => 'landingPageClicks',
        'LEAD_GENERATION' => 'oneClickLeads',
        'VIDEO_VIEW' => 'videoViews',
        'WEBSITE_CONVERSION' => 'externalWebsiteConversions',
    ];

    /**
     * The campaign's key result, read from the field its objective names; an objective LinkedIn has
     * not documented here (talent, job applicants) keeps the conversion count it always had. Absent
     * stays absent — a field LinkedIn did not send is not a result of zero.
     *
     * @param  array<string,mixed>  $row
     */
    private function resultOf(array $row, ?string $objective): ?float
    {
        $field = self::RESULT_FIELD[$objective ?? ''] ?? 'externalWebsiteConversions';

        return isset($row[$field]) ? (float) $row[$field] : null;
    }

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

    /** `["urn:li:sponsoredCreative:11"]` → `"11"`. */
    private function creativeIdFrom(mixed $pivotValues): ?string
    {
        foreach (is_array($pivotValues) ? $pivotValues : [] as $urn) {
            $id = $this->idFromUrn($urn, 'sponsoredCreative');
            if ($id !== null) {
                return $id;
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

    /*
     * REACH-PERIOD-001 — LinkedIn's `approximateMemberReach` with `timeGranularity=ALL` is one figure for
     * the whole range, on non-demographic pivots (ACCOUNT and CAMPAIGN here), for ranges of at most 92
     * days. With `ALL`, dates older than six months are rounded to whole months — the answer would be
     * for a different window than the one shown — so such a window is not asked for.
     */
    public function periodReachGrains(): array
    {
        return [ReportsPeriodReach::ACCOUNT, ReportsPeriodReach::CAMPAIGN];
    }

    public function periodReachWindowSupported(string $from, string $to): bool
    {
        $start = Carbon::parse($from);

        return $start->diffInDays(Carbon::parse($to)) + 1 <= self::REACH_MAX_DAYS
            && $start->greaterThanOrEqualTo(Carbon::now()->subMonthsNoOverflow(6)->startOfDay());
    }

    public function periodReach(string $adAccountId, string $grain, string $from, string $to): array
    {
        $account = $grain === ReportsPeriodReach::ACCOUNT;

        $reported = $this->readAll($this->tokens(), 'adAnalytics', 'period reach', [
            'q' => 'analytics',
            'pivot' => $account ? 'ACCOUNT' : 'CAMPAIGN',
            'timeGranularity' => 'ALL',
        ], [
            'dateRange' => $this->dateRange($from, $to),
            'accounts' => 'List('.rawurlencode("urn:li:sponsoredAccount:{$adAccountId}").')',
            'fields' => 'pivotValues,impressions,approximateMemberReach',
        ]);

        $rows = [];

        foreach ($reported as $row) {
            $id = $account ? $adAccountId : $this->campaignIdFrom($row['pivotValues'] ?? null);

            if ($id === null || $id === '') {
                continue;
            }

            $rows[] = [
                'external_id' => $id,
                'reach' => is_numeric($row['approximateMemberReach'] ?? null) ? (float) $row['approximateMemberReach'] : null,
                'impressions' => is_numeric($row['impressions'] ?? null) ? (float) $row['impressions'] : null,
                // LinkedIn returns no frequency; the reader derives impressions ÷ reach.
                'frequency' => null,
            ];
        }

        return $rows;
    }
}
