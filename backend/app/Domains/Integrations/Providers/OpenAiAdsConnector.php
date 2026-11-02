<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ChatGPT Ads — the OpenAI Advertiser API, `https://api.ads.openai.com/v1`.
 *
 * ## What is different about this one
 *
 * Every other connector here starts from a consent screen and a token that expires. This one starts
 * from a key the advertiser created in their own console, and the key is scoped to ONE ad account.
 * Two consequences run through the whole file:
 *
 *   - `GET /v1/ad_account` is both the credential check and the account discovery. It answers with
 *     the one account the key belongs to, or it refuses the key. There is no account list to page
 *     through and no parent level to choose inside, which is why `ProviderHierarchy` does not name
 *     one for this provider and the wizard does not draw the step.
 *   - the account id is not a parameter. Campaigns, ad groups and ads are read from collection
 *     endpoints that are already scoped by the key — so the KEY decides whose rows come back, and
 *     `$adAccountId` only says whose rows the caller is about to store. ACCOUNT-SCOPE-ISOLATION-002:
 *     those two must be the same account, and every read here proves it first (`assertKeyOwns()`).
 *     Before that proof existed, an account row whose connection had since been re-keyed for a
 *     different advertiser synced that advertiser's campaigns under its own name and into its own
 *     project — which is how one customer's ChatGPT Ads spend reached another customer's report on
 *     Production (2026-11-01). A mismatch now refuses the read and stores nothing.
 *
 * ## Ad group, not ad set
 *
 * OpenAI's hierarchy is account → campaign → ad group → ad. The middle rung maps onto this product's
 * `ExternalAdSet` because that is the canonical row for «the layer beneath a campaign», and the
 * INTERFACE calls it an ad group wherever this provider's structure is shown — the provider's own
 * word, which is the rule `ProviderHierarchy` already states for Snapchat's «Organization».
 *
 * ## What is not invented
 *
 * Only fields the API actually returns are stored. Revenue, ROAS and order counts are absent from
 * the insights contract unless the account reports conversions, and nothing here manufactures them:
 * a metric the response omits is left out of the row entirely, so it reaches the reader as «not
 * reported» rather than as a measured zero (CONTENT-RESULT-AVAILABILITY-001).
 */
final class OpenAiAdsConnector extends ApiAdvertisingConnector implements ReportsEntityGrains
{
    /** The first refusal of the last entity sweep — see {@see ReportsEntityGrains::lastEntityFailure()}. */
    private ?string $entityFailure = null;

    /**
     * ACCOUNT-SCOPE-ISOLATION-002 — account ids this instance has proved the current key to own.
     *
     * One `GET /v1/ad_account` per (key, account) rather than one per fetch: a structure sync asks for
     * campaigns, then ad groups (which re-read campaigns), then ads (which re-read both). The instance
     * is a clone bound to ONE connection (`withConnection()`), so a memo here never outlives the key
     * it was proved against — a rotated key is a new connection row and a new bound clone.
     *
     * @var array<string, true>
     */
    private array $provedOwned = [];

    protected function platform(): string
    {
        return 'openai_ads';
    }

    /**
     * The one account the key belongs to — validation and discovery in a single call.
     *
     * A key that is wrong, revoked or scoped to nothing produces a refusal here, which is exactly
     * what the connection flow needs: there is no later step at which a bad key could be discovered
     * more cheaply, and no account picker that could be populated without this answer.
     */
    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $account = $this->read(
            $this->api($tokens)->get($this->url('ad_account')),
            'the ChatGPT Ads account',
        );

        $id = (string) ($account['id'] ?? '');

        if ($id === '') {
            return [];
        }

        return [[
            'external_id' => $id,
            'name' => (string) ($account['name'] ?? $id),
            'currency' => $this->stringOrNull($account, 'currency'),
            'timezone' => $this->stringOrNull($account, 'timezone'),
            'status' => strtolower((string) ($account['status'] ?? 'active')),
            /*
             * No parent. OpenAI publishes no Business Centre, Portfolio or Manager Account for
             * advertising, and declaring one would be a wizard step populated with something derived
             * to fill the box — see `ProviderHierarchy`.
             */
            'parent_external_id' => null,
            'raw' => $account,
        ]];
    }

    /**
     * ACCOUNT-SCOPE-ISOLATION-002 — the key must belong to the account whose rows are about to be stored.
     *
     * The collection endpoints take no account id, so this is the ONLY place the product can tell
     * whether «the campaigns this key lists» are the campaigns of `$adAccountId`. The provider answers
     * `GET /v1/ad_account` with the one account the key is scoped to; anything else means the stored
     * account row and the stored credential have drifted apart, and a sync that went ahead would file
     * another advertiser's figures under this account's name and project.
     *
     * Thrown rather than returned empty, so the run is recorded as FAILED with this sentence and the
     * operator sees «re-enter this account's own key» instead of «no data» — and so that nothing is
     * written: an empty result would be stored as a day with no delivery.
     */
    private function assertKeyOwns(OAuthTokens $tokens, string $adAccountId): void
    {
        $memo = hash('sha256', $tokens->accessToken).'|'.$adAccountId;

        if (isset($this->provedOwned[$memo])) {
            return;
        }

        $account = $this->read($this->api($tokens)->get($this->url('ad_account')), 'the ChatGPT Ads account');
        $owned = (string) ($account['id'] ?? '');

        if ($owned === '' || $owned !== $adAccountId) {
            throw new RuntimeException(
                'ACCOUNT-SCOPE-ISOLATION-002: the stored ChatGPT Ads key belongs to a different ad account than the one '
                .'being synced ('.($owned === '' ? 'the provider named no account' : 'provider account '.$owned)
                .' ≠ '.$adAccountId.'). Nothing was read or stored. Re-enter this account\'s own key to resume.',
            );
        }

        $this->provedOwned[$memo] = true;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $this->assertKeyOwns($tokens, $adAccountId);

        $campaigns = [];

        foreach ($this->readPages($tokens, 'campaigns', 'campaigns') as $c) {
            $id = (string) ($c['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $campaigns[] = [
                'external_id' => $id,
                'name' => (string) ($c['name'] ?? $id),
                'status' => strtolower((string) ($c['status'] ?? 'unknown')),
                'objective' => $this->stringOrNull($c, 'objective'),
                'daily_budget' => $this->moneyOrNull($c, 'daily_budget'),
                'lifetime_budget' => $this->moneyOrNull($c, 'lifetime_budget'),
                'currency' => $this->stringOrNull($c, 'currency'),
                'raw' => (array) $c,
            ];
        }

        return $campaigns;
    }

    /**
     * Ad groups, read per campaign because that is the only filter the endpoint takes.
     *
     * `campaign_external_id` is OpenAI's own campaign id: this connector has never seen our rows and
     * could not resolve one if it wanted to.
     */
    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $this->assertKeyOwns($tokens, $adAccountId);

        $groups = [];

        foreach ($this->fetchCampaigns($tokens, $adAccountId) as $campaign) {
            $campaignId = (string) $campaign['external_id'];

            foreach ($this->readPages($tokens, 'ad_groups', 'ad groups', ['campaign_id' => $campaignId]) as $g) {
                $id = (string) ($g['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $groups[] = [
                    'external_id' => $id,
                    'campaign_external_id' => (string) ($g['campaign_id'] ?? $campaignId),
                    'name' => (string) ($g['name'] ?? $id),
                    'status' => strtolower((string) ($g['status'] ?? 'unknown')),
                    'daily_budget' => $this->moneyOrNull($g, 'daily_budget'),
                    'lifetime_budget' => $this->moneyOrNull($g, 'lifetime_budget'),
                    'currency' => $this->stringOrNull($g, 'currency'),
                    'starts_at' => $this->stringOrNull($g, 'start_time'),
                    'ends_at' => $this->stringOrNull($g, 'end_time'),
                    'raw' => (array) $g,
                ];
            }
        }

        return $groups;
    }

    /**
     * The ads, with the creative OpenAI actually returns.
     *
     * The documented creative is a CHAT CARD: a title, a body, an image or file, and a destination.
     * There is no story, no video and no carousel in this provider's contract, so none is claimed —
     * `format` says `chat_card` and the content surfaces read that the same way they read any other
     * format they do not have a special case for.
     */
    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $this->assertKeyOwns($tokens, $adAccountId);

        $ads = [];

        foreach ($this->fetchAdSets($tokens, $adAccountId) as $group) {
            $groupId = (string) $group['external_id'];

            foreach ($this->readPages($tokens, 'ads', 'ads', ['ad_group_id' => $groupId]) as $a) {
                $id = (string) ($a['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $ads[] = array_filter([
                    'external_id' => $id,
                    'ad_set_external_id' => (string) ($a['ad_group_id'] ?? $groupId),
                    'campaign_external_id' => $this->stringOrNull($a, 'campaign_id') ?? (string) $group['campaign_external_id'],
                    'name' => (string) ($a['name'] ?? $id),
                    'status' => strtolower((string) ($a['status'] ?? 'unknown')),
                    'review_status' => $this->stringOrNull($a, 'review_status'),
                    // The documented field is `creative.target_url`; `destination_url` is kept for older payloads.
                    'destination_url' => $this->stringOrNull($a, 'destination_url') ?? $this->stringOrNull((array) ($a['creative'] ?? []), 'target_url'),
                    'creative' => $this->creative($id, (array) $a),
                    'raw' => (array) $a,
                ], static fn ($v) => $v !== null);
            }
        }

        return $ads;
    }

    /**
     * The chat card, where the response carries one.
     *
     * Null when it does not, rather than an empty creative with the ad's own name in it: a creative
     * row this product invented is indistinguishable from one the platform sent, and the content
     * surfaces would then report an asset nobody can open.
     *
     * @param  array<string, mixed>  $ad
     * @return array<string, mixed>|null
     */
    private function creative(string $adId, array $ad): ?array
    {
        $creative = Arr::get($ad, 'creative');

        if (! is_array($creative) || $creative === []) {
            return null;
        }

        return array_filter([
            'external_id' => (string) ($creative['id'] ?? $adId),
            'name' => $this->stringOrNull($creative, 'title') ?? $this->stringOrNull($ad, 'name'),
            // OPENAI-CHAT-CARD-TEXT-001 — the card's own words, kept as copy: the Ads API documents file
            // UPLOAD and no retrieval, so for a card with a file the text is all the product can show.
            'headline' => $this->stringOrNull($creative, 'title'),
            'body' => $this->stringOrNull($creative, 'body'),
            /* OpenAI's documented ad creative. Not a story, not a video, not a carousel. */
            'format' => 'chat_card',
            'thumbnail_url' => $this->stringOrNull($creative, 'image_url'),
            'asset_url' => $this->stringOrNull($creative, 'image_url') ?? $this->stringOrNull($creative, 'file_url'),
            'preview_url' => $this->stringOrNull($creative, 'preview_url'),
            'destination_url' => $this->stringOrNull($creative, 'target_url'),
            /*
             * OPENAI-COVER-EVIDENCE-001 — the creative exactly as OpenAI sent it, text and file id and
             * all. A card with no `image_url` is either a text-only chat card by design or a field this
             * mapping does not read; without the payload nobody can tell which.
             */
            'raw' => array_filter([
                'type' => $this->stringOrNull($creative, 'type'),
                'file_id' => $this->stringOrNull($creative, 'file_id'),
                'body' => $this->stringOrNull($creative, 'body'),
                'keys' => array_keys($creative),
            ], static fn ($v) => $v !== null && $v !== []),
        ], static fn ($v) => $v !== null);
    }

    /**
     * Daily campaign rows — OPENAI-ADS-INSIGHTS-001.
     *
     * Production, 2026-10-12: every sync of a live account answered «no data» while Ads Manager
     * showed $556 of spend. The read asked `campaigns/{id}/insights` with `start_date`, `end_date` and
     * `granularity` — none of which the API takes — and looked for a `date` key the rows do not have
     * (a daily row names its day `readable_time`), so every row was skipped. Without `fields[]` the API
     * also returns impressions only, and without paging it returns the first 20 rows.
     *
     * Now, as the Insights reference documents it (developers.openai.com/ads/api-reference/insights):
     * ONE `GET /ad_account/insights` with `aggregation_level=campaign`, `time_granularity=daily`, the
     * window as `time_ranges[]` = `{"type":"date_range","since","until"}` (inclusive `until`, read in
     * the ad account's own timezone — the same days Ads Manager shows), the delivery fields named in
     * `fields[]`, paged by `after`/`has_more`. Conversions are not a GET metric; they come from
     * `POST /conversions/insights` for the same campaigns and days and are merged in.
     *
     * A metric the response omits is OMITTED from the row, so the reader says «not reported».
     */
    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $this->assertKeyOwns($tokens, $adAccountId);

        $rows = [];

        foreach ($this->insightRows($tokens, 'campaign', $from, $to) as $row) {
            $campaignId = $this->stringOrNull($row, 'campaign_id');
            $date = $this->dayOf($row);

            if ($campaignId === null || $date === null) {
                continue;
            }

            $rows["{$campaignId}|{$date}"] = ['campaign_id' => $campaignId, 'date' => $date] + $this->measures($row);
        }

        /*
         * Conversions, merged by campaign and day. A refusal here costs the conversion column only:
         * the delivery figures above are already read, and the run says which metric was left out.
         */
        $campaignIds = array_values(array_unique(array_map(static fn (array $r): string => $r['campaign_id'], $rows)));
        try {
            foreach ($this->conversionRows($tokens, $campaignIds, $from, $to) as $key => $measures) {
                if (isset($rows[$key])) {
                    $rows[$key] += $measures;
                }
            }
        } catch (RuntimeException $e) {
            $this->refusedMetrics = array_values(array_unique([...$this->refusedMetrics, 'conversions']));
            Log::warning('ChatGPT Ads conversion insights were refused; delivery figures were kept.', ['reason' => $e->getMessage()]);
        }

        return array_values($rows);
    }

    /** One grain of one account, for one window — STRUCT-001's ad-group and ad figures. */
    public function entityInsights(
        string $adAccountId,
        string $grain,
        array $campaignExternalIds,
        string $from,
        string $to,
    ): SyncResult {
        $this->entityFailure = null;
        $tokens = $this->tokens();
        $rows = [];

        // The same proof as every other read: a refused key is a failure, never an empty grain.
        try {
            $this->assertKeyOwns($tokens, $adAccountId);
        } catch (\Throwable $e) {
            $this->entityFailure = $e->getMessage();

            return SyncResult::of([]);
        }

        $level = $grain === ReportsEntityGrains::AD_SET ? 'ad_group' : 'ad';

        try {
            foreach ($this->insightRows($tokens, $level, $from, $to) as $row) {
                $entity = $this->stringOrNull($row, "{$level}_id");
                $date = $this->dayOf($row);

                if ($entity === null || $date === null) {
                    continue;
                }

                /*
                 * `entity_id` is the key the contract names and the upsert reads. The first version
                 * of this method wrote the id under a key of its own, so on Production every
                 * ad-group and ad row «came back and matched no discovered entity» — the ids were
                 * right and the key was not, and the message blamed discovery for a row the upsert
                 * never looked at. `EntityGrainContractSourceGuardTest` holds the key now.
                 */
                $rows[] = ['entity_id' => $entity, 'date' => $date] + $this->measures($row);
            }
        } catch (\Throwable $e) {
            /* An empty grain and a refused one are different facts. */
            $this->entityFailure = $e->getMessage();
        }

        return SyncResult::of($rows);
    }

    public function lastEntityFailure(): ?string
    {
        return $this->entityFailure;
    }

    /**
     * The measures a row actually carries — absent stays absent.
     *
     * `ctr`, `cpc` and `cpm` are deliberately NOT stored: this product derives every ratio from the
     * figures it holds, and storing a provider's own rounding beside its inputs is how two surfaces
     * come to disagree about one creative's CTR.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, float>
     */
    private function measures(array $row): array
    {
        $out = [];

        foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $out[$key] = (float) $row[$key];
        }

        return $out;
    }

    /** The delivery metrics the GET insights endpoints publish; conversions are read separately. */
    private const DELIVERY_METRICS = ['impressions', 'clicks', 'spend'];

    /**
     * Daily rows at one aggregation level across the whole account, every page.
     *
     * The query is built by hand: the API reads list parameters as `fields[]=…` repeated and refuses
     * `time_ranges` sent any other way, which PHP's indexed `fields[0]=` form would not satisfy.
     * Raw request parameters and the response's shape are logged (never the key, which travels in
     * the Authorization header only).
     *
     * A page of rows none of which carries a day or an id is not «no data» — it is a response this
     * reader does not understand, and it is raised as such rather than stored as an empty period.
     *
     * @return list<array<string, mixed>>
     */
    private function insightRows(OAuthTokens $tokens, string $level, string $from, string $to): array
    {
        $fields = ['metadata.readable_time', "{$level}.id", ...array_map(static fn (string $m): string => "{$level}.{$m}", self::DELIVERY_METRICS)];
        $params = [
            'aggregation_level' => $level,
            'time_granularity' => 'daily',
            'limit' => '2000',
        ];
        $lists = [
            'fields' => $fields,
            'time_ranges' => [json_encode(['type' => 'date_range', 'since' => $from, 'until' => $to], JSON_THROW_ON_ERROR)],
        ];

        $out = [];
        $after = null;

        for ($page = 0; $page < 50; $page++) {
            $query = self::query($params + ($after === null ? [] : ['after' => $after]), $lists);
            $body = $this->read($this->api($tokens)->get($this->url('ad_account/insights').'?'.$query), 'ChatGPT Ads insights');
            $data = Arr::get($body, 'data', []);
            $data = is_array($data) ? array_values(array_filter($data, 'is_array')) : [];

            Log::info('ChatGPT Ads insights read.', [
                'params' => $params + ['after' => $after, 'time_ranges' => $lists['time_ranges'], 'fields' => $fields],
                'response_keys' => array_keys($body),
                'row_keys' => $data === [] ? [] : array_keys($data[0]),
                'rows' => count($data),
                'has_more' => Arr::get($body, 'has_more'),
            ]);

            $this->countRawInsightRows(count($data));

            if ($data !== [] && array_filter($data, fn (array $r): bool => $this->dayOf($r) !== null && $this->stringOrNull($r, "{$level}_id") !== null) === []) {
                throw new RuntimeException('ChatGPT Ads insights returned '.count($data).' row(s) with none carrying a day and a '.$level.' id; row keys: '.implode(', ', array_keys($data[0])).'. Not stored as «no data».');
            }

            array_push($out, ...$data);

            $next = Arr::get($body, 'last_id');
            if (Arr::get($body, 'has_more') !== true || ! is_string($next) || $next === '') {
                break;
            }
            $after = $next;
        }

        return $out;
    }

    /**
     * Goal conversions and attributed sales per campaign and day, from `POST /conversions/insights`.
     *
     * The endpoint answers at most 2,000 rows with no cursor (HTTP 413 beyond), spans at most 365
     * days, and needs the campaign ids — so the window is read in 90-day slices of up to 20
     * campaigns each (≤ 1,800 rows).
     *
     * @param  list<string>  $campaignIds
     * @return array<string, array<string, float>> "campaign|date" => measures
     */
    private function conversionRows(OAuthTokens $tokens, array $campaignIds, string $from, string $to): array
    {
        $out = [];
        if ($campaignIds === []) {
            return $out;
        }

        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        for ($since = $start->copy(); $since->lte($end); $since->addDays(90)) {
            $until = $since->copy()->addDays(89)->min($end);

            foreach (array_chunk($campaignIds, 20) as $ids) {
                $body = $this->read(
                    $this->api($tokens)->post($this->url('conversions/insights'), [
                        'aggregation_level' => 'campaign',
                        'time_ranges' => [json_encode(['type' => 'date_range', 'since' => $since->toDateString(), 'until' => $until->toDateString()], JSON_THROW_ON_ERROR)],
                        'time_granularity' => 'daily',
                        'entity_ids' => $ids,
                    ]),
                    'ChatGPT Ads conversion insights',
                );

                foreach ((array) Arr::get($body, 'data', []) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $campaign = $this->stringOrNull($row, 'entity_id');
                    $date = $this->dayOf($row);
                    if ($campaign === null || $date === null) {
                        continue;
                    }
                    $measures = [];
                    if (is_numeric($row['conversions'] ?? null)) {
                        $measures['conversions'] = (float) $row['conversions'];
                    }
                    // Attributed purchase value, an unrounded decimal string — null when unavailable stays absent.
                    if (is_numeric($row['order_created_attributed_sales'] ?? null)) {
                        $measures['revenue'] = (float) $row['order_created_attributed_sales'];
                    }
                    $out["{$campaign}|{$date}"] = $measures;
                }
            }
        }

        return $out;
    }

    /** The day a row covers: `date` on conversion rows, `readable_time` on delivery rows. */
    private function dayOf(array $row): ?string
    {
        $day = $this->stringOrNull($row, 'date') ?? $this->stringOrNull($row, 'readable_time');

        return $day !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $day) === 1 ? substr($day, 0, 10) : null;
    }

    /**
     * A query string with list parameters repeated as `name[]=value`, as the API documents them.
     *
     * @param  array<string, string>  $params
     * @param  array<string, list<string>>  $lists
     */
    private static function query(array $params, array $lists): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = rawurlencode($key).'='.rawurlencode($value);
        }
        foreach ($lists as $key => $values) {
            foreach ($values as $value) {
                $parts[] = rawurlencode($key).'[]='.rawurlencode($value);
            }
        }

        return implode('&', $parts);
    }

    /**
     * Cursor pagination, as the provider documents it: `after` carries the previous page's last id
     * and `has_more` says whether to ask again.
     *
     * Bounded, like every other sweep here: a provider that answers `has_more` forever would
     * otherwise hold a queue worker open until the job timeout, and a partial page is a better
     * failure than a stuck sync.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function readPages(OAuthTokens $tokens, string $path, string $what, array $query = []): array
    {
        $out = [];
        $after = null;

        for ($page = 0; $page < 50; $page++) {
            $body = $this->read(
                $this->api($tokens)->get($this->url($path), array_filter($query + [
                    'limit' => '100',
                    'after' => $after,
                ], static fn ($v) => $v !== null)),
                $what,
            );

            $data = Arr::get($body, 'data', []);

            if (! is_array($data) || $data === []) {
                break;
            }

            foreach ($data as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }

            if (Arr::get($body, 'has_more') !== true) {
                break;
            }

            $last = end($data);
            $after = is_array($last) ? ($last['id'] ?? null) : null;

            if (! is_string($after) || $after === '') {
                break;
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $row */
    private function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param  array<string, mixed>  $row */
    private function moneyOrNull(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }
}
