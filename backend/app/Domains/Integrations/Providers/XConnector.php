<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Reporting\ReportingWindow;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Support\Carbon;

/**
 * X (Twitter) Ads API (`ads-api.x.com/12`).
 *
 * Its stats endpoint is the strictest of the six: it will not report on an account, only on named
 * entities, at most 20 at a time, and it returns metrics as ARRAYS indexed by day rather than one row
 * per day. So campaigns are fetched first, then their ids are chunked, and each chunk's day-indexed
 * arrays are unrolled back into ordinary daily rows.
 *
 * Money is in micros of the account currency, as with Snapchat and Google.
 *
 * Awaiting credentials — and X Ads access is a separate approval tier that a normal developer account
 * does not carry.
 */
final class XConnector extends ApiAdvertisingConnector implements ReportsCreativeInsights
{
    private const MICRO = 1_000_000;

    /** The documented ceiling for `entity_ids` on a single stats call. */
    private const ENTITIES_PER_CALL = 20;

    /** X answers synchronous DAY stats for at most seven days per request; longer windows are asked in pieces. */
    private const MAX_DAYS_PER_REQUEST = 7;

    protected function platform(): string
    {
        return 'x';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read($this->api($tokens)->get($this->url('accounts')), 'ad accounts');

        $accounts = [];

        foreach ((array) ($body['data'] ?? []) as $a) {
            if (($a['id'] ?? null) === null) {
                continue;
            }

            $accounts[] = [
                'external_id' => (string) $a['id'],
                'name' => (string) ($a['name'] ?? $a['id']),
                'currency' => null,
                'timezone' => isset($a['timezone']) ? (string) $a['timezone'] : null,
                'status' => ($a['deleted'] ?? false) === true ? 'inactive' : 'active',
                'parent_external_id' => null,
                'raw' => (array) $a,
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("accounts/{$adAccountId}/campaigns"), ['count' => 200]),
            'campaigns',
        );

        $campaigns = [];

        foreach ((array) ($body['data'] ?? []) as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $c['id'],
                'name' => (string) ($c['name'] ?? $c['id']),
                'status' => strtolower((string) ($c['entity_status'] ?? 'unknown')),
                'objective' => isset($c['objective']) ? (string) $c['objective'] : null,
                'daily_budget' => isset($c['daily_budget_amount_local_micro'])
                    ? (float) $c['daily_budget_amount_local_micro'] / self::MICRO
                    : null,
                'lifetime_budget' => isset($c['total_budget_amount_local_micro'])
                    ? (float) $c['total_budget_amount_local_micro'] / self::MICRO
                    : null,
                'currency' => isset($c['currency']) ? (string) $c['currency'] : null,
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url("accounts/{$adAccountId}/line_items"), ['count' => 200]),
            'line items',
        );

        $items = [];

        foreach ((array) ($body['data'] ?? []) as $l) {
            if (($l['id'] ?? null) === null || ($l['campaign_id'] ?? null) === null) {
                continue;
            }

            $items[] = [
                'external_id' => (string) $l['id'],
                'campaign_external_id' => (string) $l['campaign_id'],
                'name' => (string) ($l['name'] ?? $l['id']),
                'status' => strtolower((string) ($l['entity_status'] ?? 'unknown')),
                'optimization_goal' => isset($l['goal']) ? strtolower((string) $l['goal']) : null,
                'bid_strategy' => isset($l['bid_strategy']) ? strtolower((string) $l['bid_strategy']) : null,
                // X budgets the campaign; a line item carries a bid and a total, not a daily budget.
                'daily_budget' => null,
                'lifetime_budget' => isset($l['total_budget_amount_local_micro'])
                    ? (float) $l['total_budget_amount_local_micro'] / self::MICRO
                    : null,
                'currency' => isset($l['currency']) ? (string) $l['currency'] : null,
                'targeting' => array_filter([
                    'product_type' => isset($l['product_type']) ? strtolower((string) $l['product_type']) : null,
                    'placements' => $l['placements'] ?? null,
                ], static fn ($v) => $v !== null && $v !== []) ?: null,
                'starts_at' => isset($l['start_time']) ? (string) $l['start_time'] : null,
                'ends_at' => isset($l['end_time']) ? (string) $l['end_time'] : null,
                'raw' => (array) $l,
            ];
        }

        return $items;
    }

    /**
     * X's ads are promoted tweets, and a promoted tweet names only its line item.
     *
     * The campaign is therefore resolved from the line items — one extra call, rather than leaving
     * every ad unattached to a campaign and letting the importer guess.
     */
    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $campaignOfLineItem = [];

        foreach ($this->fetchAdSets($tokens, $adAccountId) as $lineItem) {
            $campaignOfLineItem[$lineItem['external_id']] = $lineItem['campaign_external_id'];
        }

        $body = $this->read(
            $this->api($tokens)->get($this->url("accounts/{$adAccountId}/promoted_tweets"), ['count' => 200]),
            'promoted tweets',
        );

        $ads = [];
        $tweets = $this->tweetsById($tokens, $adAccountId, array_values(array_filter(array_map(
            static fn ($p): ?string => is_array($p) && isset($p['tweet_id']) ? (string) $p['tweet_id'] : null,
            (array) ($body['data'] ?? []),
        ))));

        foreach ((array) ($body['data'] ?? []) as $p) {
            if (($p['id'] ?? null) === null) {
                continue;
            }

            $lineItemId = isset($p['line_item_id']) ? (string) $p['line_item_id'] : null;
            $tweetId = isset($p['tweet_id']) ? (string) $p['tweet_id'] : null;

            $ads[] = array_filter([
                'external_id' => (string) $p['id'],
                'ad_set_external_id' => $lineItemId,
                'campaign_external_id' => $lineItemId === null ? null : ($campaignOfLineItem[$lineItemId] ?? null),
                // A promoted tweet has no name of its own; the tweet it promotes is its identity.
                'name' => $tweetId !== null ? "Tweet {$tweetId}" : (string) $p['id'],
                'status' => strtolower((string) ($p['entity_status'] ?? 'unknown')),
                'review_status' => match (strtoupper((string) ($p['approval_status'] ?? ''))) {
                    'ACCEPTED' => 'approved',
                    'UNDER_APPEAL', 'PENDING' => 'pending',
                    'REJECTED' => 'rejected',
                    default => null,
                },
                'destination_url' => null,
                'creative' => $tweetId === null ? null : ($tweets[$tweetId] ?? [
                    'external_id' => $tweetId,
                    'name' => "Tweet {$tweetId}",
                    'format' => 'text',
                ]),
                'raw' => (array) $p,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    /**
     * CREATIVE-SYNC-MEDIA-001 — the promoted tweets' own words and picture, through the Ads API itself.
     *
     * `GET /12/accounts/:account_id/tweets?tweet_ids=…&tweet_type=PUBLISHED` (docs.x.com/x-ads-api/creatives)
     * answers on the host and with the OAuth 1.0a signing this connector already uses, so no second
     * credential and no new scope: `full_text`, `card_uri` and `entities`. A photo is read from
     * `entities.media[].media_url_https` where the answer carries one. The Ads API documents NO video file or
     * `media_keys` resolution on this read (that is the X API v2 `expansions=attachments.media_keys`, a
     * different host and credential this product does not hold), so a tweet's film is shown by its poster
     * frame only when that field is present, and never as a file.
     *
     * A refused read leaves the old minimal creative: the tweet id, as before.
     *
     * @param  list<string>  $tweetIds
     * @return array<string, array<string, mixed>> keyed by tweet id
     */
    private function tweetsById(OAuthTokens $tokens, string $adAccountId, array $tweetIds): array
    {
        $out = [];

        foreach (array_chunk(array_values(array_unique($tweetIds)), self::ENTITIES_PER_CALL) as $chunk) {
            try {
                $body = $this->read($this->api($tokens)->get($this->url("accounts/{$adAccountId}/tweets"), [
                    'tweet_ids' => implode(',', $chunk),
                    'tweet_type' => 'PUBLISHED',
                    'trim_user' => 'true',
                ]), 'tweets');
            } catch (\Throwable) {
                continue;
            }

            foreach ((array) ($body['data'] ?? []) as $t) {
                $id = is_array($t) ? (string) ($t['id_str'] ?? $t['id'] ?? '') : '';

                if ($id === '') {
                    continue;
                }

                $text = is_string($t['full_text'] ?? null) && trim($t['full_text']) !== '' ? trim($t['full_text']) : (is_string($t['text'] ?? null) && trim($t['text']) !== '' ? trim($t['text']) : null);
                $media = (array) (($t['extended_entities']['media'] ?? null) ?: ($t['entities']['media'] ?? []));
                $first = is_array($media[0] ?? null) ? $media[0] : [];
                $still = is_string($first['media_url_https'] ?? null) && str_starts_with($first['media_url_https'], 'https://') ? $first['media_url_https'] : null;
                $isFilm = in_array($first['type'] ?? null, ['video', 'animated_gif'], true);

                $out[$id] = array_filter([
                    'external_id' => $id,
                    'name' => "Tweet {$id}",
                    'headline' => null,
                    'body' => $text,
                    // A photo is an image; a film is named a film but only its poster frame is stored.
                    'format' => $still === null ? ($text === null ? 'text' : 'text_card') : ($isFilm ? 'video' : 'image'),
                    'thumbnail_url' => $still,
                    'asset_url' => $isFilm ? null : $still,
                    'preview_url' => null,
                    'raw' => array_filter([
                        'card_uri' => is_string($t['card_uri'] ?? null) ? $t['card_uri'] : null,
                        'media_type' => is_string($first['type'] ?? null) ? $first['type'] : null,
                    ], static fn ($v) => $v !== null),
                ], static fn ($v) => $v !== null && $v !== []);
            }
        }

        return $out;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $campaigns = $this->fetchCampaigns($tokens, $adAccountId);
        $campaignIds = array_map(static fn (array $c): string => $c['external_id'], $campaigns);

        /*
         * MONEY-CURRENCY-NEVER-ASSUMED-001 — the unit of every `*_local_micro` figure, per campaign.
         *
         * The X account object carries no currency (docs.x.com/x-ads-api/campaign-management, Accounts:
         * `name`, `timezone`, `timezone_switch_at`, `id`, `created_at`, `salt`, `updated_at`,
         * `approval_status`, `deleted`), so `fetchAdAccounts()` can only ever record NULL, and the stats
         * endpoint answers bare micros. The CAMPAIGN object does state `currency` (same page, Campaigns;
         * ISO 4217, taken from its funding instrument), so each stats row is labelled from the campaign it
         * belongs to. A campaign that states none gives a row with no `currency` key, and the pipeline
         * withholds that money — it is never assumed to be the reporting currency.
         */
        $currencies = [];
        foreach ($campaigns as $c) {
            if (is_string($c['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', $c['currency']) === 1) {
                $currencies[$c['external_id']] = strtoupper($c['currency']);
            }
        }

        if ($campaignIds === []) {
            return []; // nothing to ask about; the account has no campaigns in this window
        }

        /*
         * X-STATS-WINDOW-001 — Production refused every stats call with «Expect time to be midnight in the
         * account's local timezone for day granularity · INVALID_TIME_WINDOW». The window was built at UTC
         * midnight; X wants midnight in the AD ACCOUNT's own timezone, which discovery already records, and it
         * answers at most seven days per synchronous request. `ReportingWindow` — the same rule Snapchat uses —
         * places the days in that zone (refusing, rather than guessing, when none was recorded), and `chunked`
         * cuts a longer backfill into seven-day pieces that reassemble idempotently on (campaign, date, metric).
         * The end is exclusive: midnight after the last day.
         */
        $window = ReportingWindow::localDays($this->accountTimezone($adAccountId), $from, $to);
        $rows = [];

        foreach ($window->chunked(self::MAX_DAYS_PER_REQUEST) as $piece) {
            foreach (array_chunk($campaignIds, self::ENTITIES_PER_CALL) as $chunk) {
                $rows = [...$rows, ...$this->fetchStatsPiece($tokens, $adAccountId, $chunk, $piece)];
            }
        }

        return array_map(
            static fn (array $row): array => isset($currencies[$row['campaign_id']])
                ? ['currency' => $currencies[$row['campaign_id']], ...$row]
                : $row,
            $rows,
        );
    }

    /**
     * One stats request: one window piece, up to twenty campaigns.
     *
     * @param  list<string>  $chunk
     * @return list<array<string, mixed>>
     */
    private function fetchStatsPiece(OAuthTokens $tokens, string $adAccountId, array $chunk, ReportingWindow $piece, string $entity = 'CAMPAIGN', bool $countRaw = true): array
    {
        $rows = [];
        $from = $piece->startAt()->toDateString();

        $body = $this->read(
            $this->api($tokens)->get($this->url("stats/accounts/{$adAccountId}"), [
                'entity' => $entity,
                'entity_ids' => implode(',', $chunk),
                'granularity' => 'DAY',
                /*
                 * X-001 — the groups this connector actually READS.
                 *
                 * It was `BILLING,ENGAGEMENT` while `unroll()` read `conversion_purchases` (a
                 * WEB_CONVERSION metric) and `video_total_views` (a VIDEO metric). Neither group
                 * was requested, so X never returned either one and both were mapped from a key
                 * that could not arrive. Downstream that is indistinguishable from a platform
                 * which does not report them: no error, no log, the metrics simply never existed.
                 *
                 * A metric mapped and never asked for is the quietest way to lose a figure, and
                 * this is why every connector here now builds its request FROM its own map.
                 */
                'metric_groups' => 'BILLING,ENGAGEMENT,VIDEO,WEB_CONVERSION',
                'placement' => 'ALL_ON_TWITTER',
                // ISO 8601 with the account's own offset and no fractional seconds — the shape X documents.
                'start_time' => $piece->startAt()->format('Y-m-d\TH:i:sP'),
                'end_time' => $piece->endAt()->format('Y-m-d\TH:i:sP'),
            ]),
            'daily stats',
        );

        /** @var list<mixed> $entities */
        $entities = (array) ($body['data'] ?? []);

        // X returns one record per ENTITY, each carrying its own day series — so the unit here is
        // the entity. See the note on `$rawInsightRows`: the number is not cross-platform.
        if ($countRaw) {
            $this->countRawInsightRows(count($entities));
        }

        foreach ($entities as $entity) {
            $campaignId = (string) ($entity['id'] ?? '');
            /** @var array<string,mixed> $series */
            $series = (array) (($entity['id_data'][0]['metrics'] ?? []));

            if ($campaignId === '' || $series === []) {
                continue;
            }

            foreach ($this->unroll($series, $from) as $row) {
                $rows[] = ['campaign_id' => $campaignId, ...$row];
            }
        }

        return $rows;
    }

    /**
     * X-LINKEDIN-CONTENT-001 — daily figures per CREATIVE, the promoted tweet's tweet.
     *
     * X answers stats for `entity=PROMOTED_TWEET` exactly as for campaigns (same metric groups, same twenty-id and
     * seven-day bounds, same account-timezone window). Before this, X was asked only at the CAMPAIGN grain, so no X
     * creative ever had a figure or a `last_active_at`, and every surface that lists content active in the period —
     * the report, the live link, the content library — dropped all of them.
     *
     * The creative is the TWEET (`external_creatives.external_creative_id` = tweet id, see `fetchAds`), and one tweet
     * can be promoted by several line items, so promotions are summed per tweet and day, the provider's creative id
     * travelling in `campaign_id` as `ReportsCreativeInsights` defines the row. The money is labelled with the
     * currency of the campaign each promotion belongs to (the X account states none); a tweet whose promotions spend
     * in two currencies keeps its counts and has its money withheld rather than added across currencies.
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
        $currencyOfCampaign = [];
        foreach ($this->fetchCampaigns($tokens, $adAccountId) as $c) {
            if (is_string($c['currency'] ?? null) && preg_match('/^[A-Za-z]{3}$/', $c['currency']) === 1) {
                $currencyOfCampaign[$c['external_id']] = strtoupper($c['currency']);
            }
        }

        $campaignOfLineItem = [];
        foreach ($this->fetchAdSets($tokens, $adAccountId) as $lineItem) {
            $campaignOfLineItem[$lineItem['external_id']] = $lineItem['campaign_external_id'];
        }

        $body = $this->read(
            $this->api($tokens)->get($this->url("accounts/{$adAccountId}/promoted_tweets"), ['count' => 200]),
            'promoted tweets',
        );

        $tweetOf = [];
        $currencyOf = [];
        foreach ((array) ($body['data'] ?? []) as $p) {
            if (! is_array($p) || ($p['id'] ?? null) === null || ($p['tweet_id'] ?? null) === null) {
                continue;
            }
            $tweetOf[(string) $p['id']] = (string) $p['tweet_id'];
            $campaign = $campaignOfLineItem[(string) ($p['line_item_id'] ?? '')] ?? null;
            $currencyOf[(string) $p['id']] = $campaign === null ? null : ($currencyOfCampaign[$campaign] ?? null);
        }

        if ($tweetOf === []) {
            return [];
        }

        $window = ReportingWindow::localDays($this->accountTimezone($adAccountId), $from, $to);
        $rows = [];
        $currencies = [];

        foreach ($window->chunked(self::MAX_DAYS_PER_REQUEST) as $piece) {
            foreach (array_chunk(array_keys($tweetOf), self::ENTITIES_PER_CALL) as $chunk) {
                foreach ($this->fetchStatsPiece($tokens, $adAccountId, array_map('strval', $chunk), $piece, 'PROMOTED_TWEET', countRaw: false) as $row) {
                    $promoted = (string) $row['campaign_id'];
                    $tweet = $tweetOf[$promoted] ?? null;
                    if ($tweet === null) {
                        continue;
                    }
                    $key = $tweet.'|'.$row['date'];
                    $rows[$key] ??= ['campaign_id' => $tweet, 'date' => $row['date']];
                    $currencies[$key][] = $currencyOf[$promoted] ?? null;

                    foreach ($row as $metric => $value) {
                        if (in_array($metric, ['campaign_id', 'date'], true) || ! is_numeric($value)) {
                            continue;
                        }
                        $rows[$key][$metric] = ($rows[$key][$metric] ?? 0.0) + (float) $value;
                    }
                }
            }
        }

        foreach ($rows as $key => $row) {
            $distinct = array_values(array_unique($currencies[$key] ?? []));
            if (count($distinct) === 1 && $distinct[0] !== null) {
                $rows[$key] = ['currency' => $distinct[0], ...$row];
            } elseif (count($distinct) > 1) {
                unset($rows[$key]['spend'], $rows[$key]['revenue']); // two currencies are never added together
            }
        }

        return array_values($rows);
    }

    /** The ad account's own timezone, as discovery recorded it — never defaulted (see ReportingWindow). */
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

    /**
     * Turn day-indexed metric arrays into one row per day.
     *
     * X answers `{"billed_charge_local_micro":[1000000,2000000], "impressions":[10,20]}` — position 0
     * is the first day of the window. A null at a position means "no data that day", which is not the
     * same as zero and is left out rather than written as one.
     *
     * @param  array<string,mixed>  $series
     * @return list<array<string,mixed>>
     */
    private function unroll(array $series, string $from): array
    {
        $start = Carbon::parse($from);

        /** @var array<int,float|null> $spend */
        $spend = (array) ($series['billed_charge_local_micro'] ?? []);
        /*
         * How many days the answer covers, measured across EVERY series X sent.
         *
         * It used to look only at spend, impressions and clicks. A window in which the account had
         * conversions but no billed spend — a paused campaign still converting from earlier
         * impressions — measured as zero days long, and every conversion in it was dropped before
         * anything could store it.
         */
        $length = 0;

        foreach ($series as $values) {
            // The conversion shape nests its arrays one level down; count the deepest list.
            $lists = is_array($values) && ! array_is_list($values) ? array_values($values) : [$values];

            foreach ($lists as $list) {
                if (is_array($list)) {
                    $length = max($length, count($list));
                }
            }
        }

        $rows = [];

        for ($day = 0; $day < $length; $day++) {
            /*
             * X-001 — the canonical set, each from the metric that means it.
             *
             * `purchases` and `conversions` are the same number HERE and are stated as such: X's
             * only conversion metric this connector reads IS the purchase one, so there is no wider
             * «results» figure to keep them apart from. On a platform where they differ — TikTok's
             * `conversion` against `complete_payment`, Google's conversion categories — they are
             * mapped apart, and the funnel reads `purchases` for its Purchase stage either way.
             *
             * `engagements` is X's own single total for the ad, which is why it is read here and
             * left null on Snapchat and TikTok: those two publish the parts and never the whole,
             * and adding the parts up would manufacture a metric.
             *
             * `landing_page_views` has no X equivalent — `clicks` is the click and
             * `conversion_site_visits` is a pixel event on arrival, which is a different
             * measurement from the delivery metric this canonical key means elsewhere. Absent.
             */
            $row = array_filter([
                'date' => $start->copy()->addDays($day)->toDateString(),
                'spend' => isset($spend[$day]) ? (float) $spend[$day] / self::MICRO : null,
                'impressions' => $this->at($series, 'impressions', $day),
                'clicks' => $this->at($series, 'clicks', $day),
                'engagements' => $this->at($series, 'engagements', $day),
                'purchases' => $this->at($series, 'conversion_purchases', $day),
                'conversions' => $this->at($series, 'conversion_purchases', $day),
                'add_to_cart' => $this->at($series, 'conversion_add_to_cart', $day),
                'checkout' => $this->at($series, 'conversion_checkouts_initiated', $day),
                'revenue' => $this->money($series, 'conversion_purchases', $day),
                'video_views' => $this->at($series, 'video_total_views', $day),
                'video_completions' => $this->at($series, 'video_views_100', $day),
            ], static fn ($v) => $v !== null);

            // A day with nothing but its own date is not a measurement.
            if (count($row) > 1) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * One day's value of a metric, whichever of X's two shapes it arrives in.
     *
     * The engagement and video groups answer a plain array — `"impressions":[10,20]`. The conversion
     * group answers an OBJECT per metric, with the count under `metric` and the money beside it:
     * `"conversion_purchases":{"metric":[3,4],"sale_amount_local_micro":[...]}`. Reading only the
     * first shape returns null for every conversion metric, which downstream is indistinguishable
     * from a platform that never reported one.
     *
     * A null AT a position is «no data that day», which is not zero, and stays null.
     *
     * @param  array<string,mixed>  $series
     */
    private function at(array $series, string $metric, int $day, string $key = 'metric'): ?float
    {
        $values = $series[$metric] ?? null;

        // The conversion shape: the numbers live one level down.
        if (is_array($values) && ! array_is_list($values)) {
            $values = $values[$key] ?? null;
        }

        if (! is_array($values) || ! isset($values[$day]) || $values[$day] === null) {
            return null;
        }

        return (float) $values[$day];
    }

    /**
     * The money a conversion metric carries, in the account's currency.
     *
     * X states conversion value in millionths beside the count, under the same metric object, so it
     * is read from there and divided once — never inferred from a count and an average.
     *
     * @param  array<string,mixed>  $series
     */
    private function money(array $series, string $metric, int $day): ?float
    {
        $micro = $this->at($series, $metric, $day, 'sale_amount_local_micro');

        return $micro === null ? null : $micro / self::MICRO;
    }
}
