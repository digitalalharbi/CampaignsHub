<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Meta Marketing API — Facebook and Instagram.
 *
 * The awkward part is conversions. Meta does not return a `conversions` number; it returns an
 * `actions` array of every action type the campaign produced — page engagement, video views, link
 * clicks, purchases — and a matching `action_values` array for the money. Summing all of them would
 * count a page like as a purchase, so {@see self::ACTIONS} names which action type carries which
 * canonical metric, and everything else is left where it is rather than quietly inflating a ROAS.
 *
 * The subtler trap, and the one that was live here until META-001, is that Meta reports the SAME
 * sale under several action types at once. Adding those together is not a bigger truth, it is the
 * same purchase counted three times — see the note on `ACTIONS`.
 *
 * Awaiting credentials on this install.
 */
final class MetaConnector extends ApiAdvertisingConnector implements ReportsEntityGrains
{
    /**
     * A ceiling on paging, so a wrong `paging.next` cannot become an unbounded loop.
     */
    private const MAX_PAGES = 50;

    /**
     * META-ATTRIB-001 — the window these figures are measured over, ASKED FOR rather than inherited.
     *
     * Meta's insights reference gives `action_attribution_windows` a default of `default`, and then
     * says what that word means: «يشير الخيار default إلى ["7d_click","1d_view"]» — seven days after
     * a click, one day after a view. So the previous code, which sent nothing, was not measuring
     * over «no window». It was measuring over THIS window without knowing it, and storing the string
     * `default` beside every figure as though the window were unknown or neutral.
     *
     * Two things follow from naming it. The stored window becomes a fact a reader can act on rather
     * than a placeholder. And it becomes DIFFERENT from the placeholder the other providers still
     * carry — which is what lets a mixed-window total be seen as mixed, instead of being flattened
     * into a single reassuring group by the very panel that exists to catch it.
     *
     * The value stays Meta's own default deliberately. A shorter or longer window would be a
     * reporting policy nobody here has chosen, applied retroactively to every client's history; this
     * changes what we KNOW about the numbers, not what the numbers are.
     *
     * @var list<string>
     */
    private const ATTRIBUTION_WINDOWS = ['7d_click', '1d_view'];

    /**
     * Canonical metric ← the Meta action types that could carry it, in priority order.
     *
     * ## First present wins. They are NOT summed (META-001)
     *
     * This used to add together every matching type, and the three purchase spellings are not three
     * different sales — they are three VIEWS OF THE SAME SALES. `offsite_conversion.fb_pixel_purchase`
     * is what the pixel saw, `purchase` is Meta's consolidated standard-event total, `omni_purchase`
     * is the cross-surface rollup. A typical account reports all three, so summing them counted every
     * sale up to three times: purchases tripled, revenue tripled, and ROAS tripled with them, on the
     * number a client judges the whole engagement by.
     *
     * The previous comment shows how it happened — it was written to fix the opposite worry, that
     * reading only one spelling «silently halves the conversions on accounts that report the other».
     * The fix for that is a fallback ORDER, which is what this is; addition was never it.
     *
     * ## And each canonical is its own event
     *
     * `view_content` appears nowhere: looking at a product is not putting it in a basket, and
     * `offsite_conversion.fb_pixel_view_content` is the single easiest way to make add-to-cart look
     * healthy. `initiate_checkout` is its own stage and never a purchase. `landing_page_view` is the
     * real landing metric — `link_click` counts the click, which is a different moment and a larger
     * number, and using it would make the funnel's landing step look better than it was.
     *
     * @var array<string, list<string>>
     */
    private const ACTIONS = [
        'purchases' => ['purchase', 'omni_purchase', 'offsite_conversion.fb_pixel_purchase'],
        'add_to_cart' => ['add_to_cart', 'omni_add_to_cart', 'offsite_conversion.fb_pixel_add_to_cart'],
        'checkout' => ['initiate_checkout', 'omni_initiated_checkout', 'offsite_conversion.fb_pixel_initiate_checkout'],
        'landing_page_views' => ['landing_page_view'],
        // Meta, unlike Snapchat and TikTok, DOES publish a single engagement total for the ad, so
        // this one is read rather than left null. `page_engagement` is deliberately not added to it:
        // it overlaps with post engagement and adding the two would invent interactions.
        'engagements' => ['post_engagement'],
    ];

    protected function platform(): string
    {
        return 'meta';
    }

    /**
     * Read a Graph collection to its END, not just its first page.
     *
     * Meta pages every edge and hands back the next page as an absolute URL in `paging.next`. A
     * `limit` of 500 is not a guarantee of completeness — it is a page size, and an account with 600
     * ads answered with 500 of them and no indication that anything was missing. The hundred that
     * vanished took their spend out of every total on every surface.
     *
     * The cursor is followed rather than rebuilt: `paging.next` already carries the `after` token,
     * the field list and the time range, and reconstructing it by hand is how one of them gets
     * dropped and the same page is fetched until the ceiling.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function readAll(OAuthTokens $tokens, string $path, string $what, array $query): array
    {
        $url = $this->url($path);
        $items = [];

        for ($page = 0; $page < self::MAX_PAGES && $url !== null; $page++) {
            // Only the FIRST request carries the query; every later one is the cursor URL entire.
            $body = $this->read(
                $page === 0 ? $this->api($tokens)->get($url, $query) : $this->api($tokens)->get($url),
                $what,
            );

            foreach ((array) ($body['data'] ?? []) as $item) {
                $items[] = (array) $item;
            }

            $next = $body['paging']['next'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $items;
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $accounts = [];

        foreach ($this->readAll($tokens, 'me/adaccounts', 'ad accounts', [
            'fields' => 'id,account_id,name,currency,timezone_name,account_status',
            'limit' => 200,
        ]) as $a) {
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
        $campaigns = [];

        foreach ($this->readAll($tokens, "{$adAccountId}/campaigns", 'campaigns', [
            'fields' => 'id,name,status,objective,daily_budget,lifetime_budget,start_time,stop_time',
            'limit' => 500,
        ]) as $c) {
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
        $sets = [];

        foreach ($this->readAll($tokens, "{$adAccountId}/adsets", 'ad sets', [
            'fields' => 'id,name,status,campaign_id,optimization_goal,bid_strategy,daily_budget,lifetime_budget,targeting,start_time,end_time',
            'limit' => 500,
        ]) as $s) {
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
        $ads = [];

        foreach ($this->readAll($tokens, "{$adAccountId}/ads", 'ads', [
            'fields' => 'id,name,status,effective_status,adset_id,campaign_id,preview_shareable_link,'
                .'creative{id,name,thumbnail_url,object_type,image_url,object_story_spec}',
            'limit' => 500,
        ]) as $a) {
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
                'destination_url' => null, // Read from the creative's story spec below, where Meta states it
                'creative' => isset($creative['id']) ? $this->creativeFrom($creative, $a) : null,
                'raw' => (array) $a,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * AD-MEDIA-RECOVERY-001 — the ad's media, from the fields Meta actually offers.
     *
     * ## What was asked for, and what that cost
     *
     * The ads request asked for `creative{id,name,thumbnail_url,object_type}` and nothing else. Meta
     * answers that faithfully, so:
     *
     *   * `asset_url` was NEVER written for Meta. The only image that ever arrived was
     *     `thumbnail_url` — a small square Meta generates for listings — and a card asked to render
     *     a preview from it showed a postage stamp or, on a video with no thumbnail, the
     *     «this platform does not expose the creative's asset» state. That sentence is about Meta,
     *     and it was really about this field list.
     *   * A CAROUSEL always rendered as one picture. `object_story_spec.link_data.child_attachments`
     *     is where its cards live; `ExternalCreative::$casts` has held a `cards` column since the
     *     presenter was written, the presenter reads it, the shared report reads it, and NOTHING HAS
     *     EVER WRITTEN IT.
     *   * `destination_url` was hardcoded null with a comment saying Meta states it inside the story
     *     spec — an accurate note about a field nobody then read.
     *
     * ## Nothing here is constructed
     *
     * Every value is a string Meta sent. A video's poster comes from `video_data.image_url`, which is
     * the frame Meta itself shows for that video — not a frame derived here, and not a placeholder.
     * A creative with no media in its response still stores nulls and still reaches the honest
     * «no preview» state, which is the whole reason those columns are nullable.
     *
     * `video_url` stays unset on purpose: Meta gives `video_id`, and turning it into a playable
     * source is a separate `/{video_id}?fields=source` call PER ASSET. That is the same per-asset
     * cost the TikTok connector declines, and it is a decision about sync budget rather than a
     * mapping — so the poster is read (free, already in this response) and the source is not.
     *
     * @param  array<string,mixed>  $creative
     * @param  array<string,mixed>  $ad
     * @return array<string,mixed>
     */
    private function creativeFrom(array $creative, array $ad): array
    {
        $spec = (array) ($creative['object_story_spec'] ?? []);
        $link = (array) ($spec['link_data'] ?? []);
        $video = (array) ($spec['video_data'] ?? []);

        $str = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? $v : null;

        $children = array_values(array_filter(
            (array) ($link['child_attachments'] ?? []),
            static fn (mixed $c): bool => is_array($c),
        ));

        return array_filter([
            'external_id' => (string) $creative['id'],
            'name' => $str($creative['name'] ?? null),
            'format' => $this->creativeFormat($creative['object_type'] ?? null),
            // The full-size image, then the story spec's own picture. Both are Meta's, never built here.
            'asset_url' => $str($creative['image_url'] ?? null) ?? $str($link['picture'] ?? null),
            // A video's poster is an image Meta already chose for it; a listing thumbnail is the fallback.
            'thumbnail_url' => $str($creative['thumbnail_url'] ?? null) ?? $str($video['image_url'] ?? null),
            'preview_url' => $str($ad['preview_shareable_link'] ?? null),
            'destination_url' => $str($link['link'] ?? null)
                ?? $str($video['call_to_action']['value']['link'] ?? null),
            /*
             * `null` when Meta sent no `child_attachments`, `[]` when it sent an empty list — two
             * different sentences the presenter already knows how to tell apart, and the reason an
             * empty array must survive the filter below. It does: only nulls are dropped.
             */
            'cards' => $children === []
                ? (isset($link['child_attachments']) ? [] : null)
                : array_map(
                    static fn (array $c): array => array_filter([
                        'name' => $str($c['name'] ?? null),
                        'description' => $str($c['description'] ?? null),
                        'image_url' => $str($c['picture'] ?? null) ?? $str($c['image_url'] ?? null),
                        'destination_url' => $str($c['link'] ?? null),
                    ], static fn ($v) => $v !== null),
                    $children,
                ),
        ], static fn ($v) => $v !== null);
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
        $reported = $this->readAll($tokens, "{$adAccountId}/insights", 'daily insights', [
            'level' => 'campaign',
            'time_increment' => 1, // one row per campaign per day
            /*
             * `video_play_actions`, not `video_30_sec_watched_actions`, for `video_views`.
             *
             * The thirty-second metric only exists for videos at least that long, so on the fifteen-
             * second creatives most of this market runs it is simply never returned — and the product
             * showed «video views» as unreported for ads that were watched hundreds of thousands of
             * times. `video_play_actions` is the play, which is what every other platform here means
             * by a view (Snapchat `video_views`, TikTok `video_play_actions`).
             */
            'fields' => 'campaign_id,date_start,spend,impressions,clicks,reach,actions,action_values,'
                .'video_play_actions,video_p100_watched_actions',
            'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
            /*
             * JSON, like the `time_range` above it. Graph reads `list<enum>` parameters as JSON, and
             * a PHP array would be serialised into `action_attribution_windows[0]=…`, which Graph
             * does not parse — it would be ignored, and an ignored parameter looks exactly like one
             * that was never sent.
             */
            'action_attribution_windows' => json_encode(self::ATTRIBUTION_WINDOWS, JSON_THROW_ON_ERROR),
            'limit' => 500,
        ]);

        $this->countRawInsightRows(count($reported));

        $rows = [];

        foreach ($reported as $row) {
            $mapped = $this->mapInsightRow($row, 'campaign_id', 'campaign_id', $from);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return $rows;
    }

    /**
     * ADSET-METRICS-TRUTH-001 — one mapping, whichever level the row came from.
     *
     * Meta answers `level=campaign`, `level=adset` and `level=ad` with the SAME fields; only the id
     * on the row changes. Writing the mapping again per level is how one of them ends up with a
     * different idea of what a conversion is — so there is one, and the caller says which key names
     * the entity and what to call it in the canonical row.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>|null null when the row names no entity
     */
    private function mapInsightRow(array $row, string $idField, string $idKey, string $from): ?array
    {
        $id = (string) ($row[$idField] ?? '');

        if ($id === '') {
            return null;
        }

        $actions = $row['actions'] ?? null;
        $purchases = $this->pick($actions, self::ACTIONS['purchases']);

        $mapped = [
            $idKey => $id,
            'date' => (string) ($row['date_start'] ?? $from),
            // What these figures MEAN, carried beside them. Read by `InsightRowNormaliser` in the
            // same way as a row's currency, and stored on every metric this row becomes.
            'attribution_window' => implode(',', self::ATTRIBUTION_WINDOWS),
            'spend' => isset($row['spend']) ? (float) $row['spend'] : null,
            'impressions' => isset($row['impressions']) ? (float) $row['impressions'] : null,
            'clicks' => isset($row['clicks']) ? (float) $row['clicks'] : null,
            'reach' => isset($row['reach']) ? (float) $row['reach'] : null,
            'revenue' => $this->pick($row['action_values'] ?? null, self::ACTIONS['purchases']),
            'video_views' => $this->total($row['video_play_actions'] ?? null),
            'video_completions' => $this->total($row['video_p100_watched_actions'] ?? null),
            /*
             * Meta publishes no generic «conversions» figure — it publishes actions, and which of
             * them is the result depends on the objective. This connector's definition of a
             * conversion has always been a purchase, so the two are the same number HERE and are
             * stated as such rather than one being quietly derived from the other. On a platform
             * where they differ (TikTok's `conversion` against `complete_payment`) they are
             * mapped apart, which is why the funnel now reads `purchases` for its Purchase stage.
             */
            'purchases' => $purchases,
            'conversions' => $purchases,
        ];

        foreach (['add_to_cart', 'checkout', 'landing_page_views', 'engagements'] as $canonical) {
            $mapped[$canonical] = $this->pick($actions, self::ACTIONS[$canonical]);
        }

        // ABSENT, not zero — every null drops out here and no surface can print it as a measured 0.
        return array_filter($mapped, static fn ($v) => $v !== null);
    }

    /** The first refusal of the last grain sweep, kept so the run log can say WHY the table is empty. */
    private ?string $entityFailure = null;

    public function lastEntityFailure(): ?string
    {
        return $this->entityFailure;
    }

    /**
     * ADSET-METRICS-TRUTH-001 — the two rungs between a campaign and an ad, from Meta.
     *
     * ## What was wrong
     *
     * Nothing here was broken; nothing here EXISTED. `AccountMetricsSyncer` asked for these grains
     * behind `if ($connector instanceof SnapchatConnector)`, so a Meta account showed «—» for
     * ad-set spend, CPC, CPM and CPA — and Meta reports all four perfectly well. The product was
     * printing our own silence as the platform's.
     *
     * ## One call, not a sweep
     *
     * Graph takes `level` on the ACCOUNT insights endpoint, so an ad-set grain is one request for
     * the whole account rather than one per campaign. The parent list the interface passes is for
     * providers whose API has no such breakdown, and is ignored here — asking Meta per campaign
     * would be eighty-nine requests for something one answers.
     *
     * The fields are the campaign grain's, plus the id of the level being asked for. Same window,
     * same attribution windows, same mapping: a figure that disagrees with the campaign total
     * because its two levels were mapped differently is worse than no figure at all, which is why
     * `mapInsightRow()` is shared rather than repeated.
     *
     * @param  list<string>  $campaignExternalIds  unused: Meta answers for the whole account at once
     */
    public function entityInsights(
        string $adAccountId,
        string $grain,
        array $campaignExternalIds,
        string $from,
        string $to,
    ): SyncResult {
        $this->entityFailure = null;

        $idField = $grain === ReportsEntityGrains::AD_SET ? 'adset_id' : 'ad_id';

        try {
            $reported = $this->readAll($this->tokens(), "{$adAccountId}/insights", "{$grain} insights", [
                'level' => $grain === ReportsEntityGrains::AD_SET ? 'adset' : 'ad',
                'time_increment' => 1,
                'fields' => "{$idField},campaign_id,date_start,spend,impressions,clicks,reach,actions,"
                    .'action_values,video_play_actions,video_p100_watched_actions',
                'time_range' => json_encode(['since' => $from, 'until' => $to], JSON_THROW_ON_ERROR),
                'action_attribution_windows' => json_encode(self::ATTRIBUTION_WINDOWS, JSON_THROW_ON_ERROR),
                'limit' => 500,
            ]);
        } catch (\Throwable $e) {
            /*
             * Contained, and recorded. A grain that fails must not cost the campaign figures already
             * ingested — but a swallowed refusal is what makes an empty table unexplainable, because
             * «not swept yet» and «refused every call» look identical from a row count.
             */
            $this->entityFailure = $e->getMessage();

            return SyncResult::failed($e->getMessage());
        }

        $rows = [];

        foreach ($reported as $row) {
            /*
             * `entity_id` is what the upsert reads, and it resolves the provider's id against what
             * the structure sweep already discovered. A row naming an ad set we have never seen is
             * skipped there rather than invented here: structure owns identity, this owns numbers.
             */
            $mapped = $this->mapInsightRow($row, $idField, 'entity_id', $from);

            if ($mapped !== null) {
                $rows[] = $mapped;
            }
        }

        return SyncResult::of($rows);
    }

    /**
     * The value of the FIRST action type present, out of a priority list. Never their sum.
     *
     * Meta reports the same sale under several action types at once — the pixel's own view, the
     * consolidated standard event, the cross-surface rollup — so adding them counts one purchase up
     * to three times. Only within a single type are values added, because Meta can repeat a type
     * across breakdown rows and those ARE different occurrences.
     *
     * Null when none of the types is present, and that is the honest answer rather than 0.0: Meta
     * omits an action type entirely when its count is zero, so «no purchase action» is
     * indistinguishable from «no pixel on this campaign». Storing a zero would state the first when
     * it might be the second, on the stage a client reads as their sales.
     *
     * @param  list<string>  $types
     */
    private function pick(mixed $actions, array $types): ?float
    {
        if (! is_array($actions)) {
            return null;
        }

        $byType = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = (string) ($action['action_type'] ?? '');

            if ($type === '') {
                continue;
            }

            $byType[$type] = ($byType[$type] ?? 0.0) + (float) ($action['value'] ?? 0);
        }

        foreach ($types as $type) {
            if (array_key_exists($type, $byType)) {
                return $byType[$type];
            }
        }

        return null;
    }

    /**
     * The whole of a single-purpose action array (`video_play_actions` and friends).
     *
     * These edges carry one action type broken down into rows, so summing them is right — unlike
     * {@see self::pick()}, where the types are competing views of the same event.
     */
    private function total(mixed $actions): ?float
    {
        if (! is_array($actions) || $actions === []) {
            return null;
        }

        $total = 0.0;

        foreach ($actions as $action) {
            if (is_array($action)) {
                $total += (float) ($action['value'] ?? 0);
            }
        }

        return $total;
    }
}
