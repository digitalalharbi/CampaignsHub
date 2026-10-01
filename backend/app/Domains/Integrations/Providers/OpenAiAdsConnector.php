<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\ValueObjects\SyncResult;
use Illuminate\Support\Arr;

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
 *     endpoints that are already scoped by the key, so `$adAccountId` arrives here and is used only
 *     to label what came back.
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

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
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
                    'destination_url' => $this->stringOrNull($a, 'destination_url'),
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
            /* OpenAI's documented ad creative. Not a story, not a video, not a carousel. */
            'format' => 'chat_card',
            'thumbnail_url' => $this->stringOrNull($creative, 'image_url'),
            'asset_url' => $this->stringOrNull($creative, 'image_url') ?? $this->stringOrNull($creative, 'file_url'),
            'preview_url' => $this->stringOrNull($creative, 'preview_url'),
        ], static fn ($v) => $v !== null);
    }

    /**
     * Daily campaign rows, from the campaign insights endpoint.
     *
     * A metric the response omits is OMITTED from the row. `UpsertDailyMetrics` and its creative
     * sibling both skip absent keys, so the column stays null and the reader says «not reported» —
     * which is the difference between «this campaign earned nothing» and «nobody measured it».
     */
    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = [];

        foreach ($this->fetchCampaigns($tokens, $adAccountId) as $campaign) {
            $campaignId = (string) $campaign['external_id'];

            foreach ($this->insightRows($tokens, "campaigns/{$campaignId}/insights", $from, $to) as $row) {
                $date = $this->stringOrNull($row, 'date');

                if ($date === null) {
                    continue;
                }

                $rows[] = ['campaign_id' => $campaignId, 'date' => $date] + $this->measures($row);
            }
        }

        return $rows;
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

        $parents = $grain === ReportsEntityGrains::AD_SET
            ? array_map(static fn (array $g): string => (string) $g['external_id'], $this->fetchAdSets($tokens, $adAccountId))
            : array_map(static fn (array $a): string => (string) $a['external_id'], $this->fetchAds($tokens, $adAccountId));

        $path = $grain === ReportsEntityGrains::AD_SET ? 'ad_groups' : 'ads';

        foreach ($parents as $parentId) {
            try {
                foreach ($this->insightRows($tokens, "{$path}/{$parentId}/insights", $from, $to) as $row) {
                    $date = $this->stringOrNull($row, 'date');

                    if ($date === null) {
                        continue;
                    }

                    $rows[] = [
                        'entity_external_id' => $parentId,
                        'date' => $date,
                    ] + $this->measures($row);
                }
            } catch (\Throwable $e) {
                /* The FIRST refusal is kept: an empty grain and a refused one are different facts. */
                $this->entityFailure ??= $e->getMessage();
            }
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

    /**
     * An insights call, with the window the provider's own parameters name.
     *
     * @return list<array<string, mixed>>
     */
    private function insightRows(OAuthTokens $tokens, string $path, string $from, string $to): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url($path), [
                'start_date' => $from,
                'end_date' => $to,
                'granularity' => 'daily',
            ]),
            'ChatGPT Ads insights',
        );

        $rows = Arr::get($body, 'data', []);

        $this->countRawInsightRows(is_array($rows) ? count($rows) : 0);

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
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
