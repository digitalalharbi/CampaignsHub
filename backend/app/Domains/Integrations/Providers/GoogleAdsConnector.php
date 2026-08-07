<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Providers;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Support\PlatformHttp;

/**
 * Google Ads API (v18 REST).
 *
 * The only one of the six with a query LANGUAGE rather than endpoints: campaigns and metrics both come
 * from `googleAds:searchStream` with GAQL, and the response is a STREAM — a JSON array of chunks, each
 * with its own `results` — so a reader that expects one object silently sees the first chunk and drops
 * the rest. That is the whole reason `stream()` exists below.
 *
 * Money is in micros here too, and the customer id must be sent with the dashes stripped even though
 * every Google console shows it with them.
 *
 * Awaiting credentials — and note that the OAuth client is not enough: Google Ads refuses every call
 * without an approved developer token, which is why it is in the platform's `requires`.
 */
final class GoogleAdsConnector extends ApiAdvertisingConnector
{
    private const MICRO = 1_000_000;

    protected function platform(): string
    {
        return 'google';
    }

    /**
     * The registry key stays `google_ads` for compatibility with every stored row that uses it, while
     * the config platform is `google` — `AdPlatforms::canonical()` maps both, and the registry keeps an
     * explicit alias so neither spelling resolves to no connector.
     */
    public function key(): string
    {
        return 'google_ads';
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read(
            $this->api($tokens)->get($this->url('customers:listAccessibleCustomers')),
            'accessible customers',
        );

        $accounts = [];

        foreach ((array) ($body['resourceNames'] ?? []) as $resource) {
            // "customers/1234567890" → "1234567890"
            $id = str_replace('customers/', '', (string) $resource);

            if ($id === '') {
                continue;
            }

            $accounts[] = [
                'external_id' => $id,
                // `listAccessibleCustomers` returns ids and nothing else; the descriptive name needs a
                // second query per customer, which is done lazily rather than N+1 on every listing.
                'name' => $this->descriptiveName($tokens, $id) ?? $id,
                'currency' => null,
                'timezone' => null,
                'status' => 'active',
                'parent_external_id' => $this->credentials()->get('login_customer_id'),
                'raw' => ['resource_name' => $resource],
            ];
        }

        return $accounts;
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT campaign.id, campaign.name, campaign.status, campaign.advertising_channel_type,
                   campaign_budget.amount_micros, campaign_budget.total_amount_micros
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            GAQL);

        $campaigns = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);
            /** @var array<string,mixed> $budget */
            $budget = (array) ($row['campaignBudget'] ?? []);

            if (($campaign['id'] ?? null) === null) {
                continue;
            }

            $campaigns[] = [
                'external_id' => (string) $campaign['id'],
                'name' => (string) ($campaign['name'] ?? $campaign['id']),
                'status' => strtolower((string) ($campaign['status'] ?? 'unknown')),
                'objective' => isset($campaign['advertisingChannelType'])
                    ? (string) $campaign['advertisingChannelType']
                    : null,
                'daily_budget' => isset($budget['amountMicros']) ? (float) $budget['amountMicros'] / self::MICRO : null,
                'lifetime_budget' => isset($budget['totalAmountMicros'])
                    ? (float) $budget['totalAmountMicros'] / self::MICRO
                    : null,
                'currency' => null,
                'raw' => $row,
            ];
        }

        return $campaigns;
    }

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.type,
                   ad_group.cpc_bid_micros, ad_group.target_cpa_micros, campaign.id
            FROM ad_group
            WHERE ad_group.status != 'REMOVED'
            GAQL);

        $groups = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $group */
            $group = (array) ($row['adGroup'] ?? []);
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);

            if (($group['id'] ?? null) === null || ($campaign['id'] ?? null) === null) {
                continue;
            }

            $groups[] = [
                'external_id' => (string) $group['id'],
                'campaign_external_id' => (string) $campaign['id'],
                'name' => (string) ($group['name'] ?? $group['id']),
                'status' => strtolower((string) ($group['status'] ?? 'unknown')),
                // Google states the optimisation target on the campaign's bidding strategy, not on the
                // ad group — so this level genuinely has none, and null is the true answer rather than
                // `ad_group.type` dressed up as a goal.
                'optimization_goal' => null,
                'bid_strategy' => isset($group['targetCpaMicros']) ? 'target_cpa' : (isset($group['cpcBidMicros']) ? 'manual_cpc' : null),
                /*
                 * Google budgets a CAMPAIGN, never an ad group.
                 *
                 * Copying the campaign's budget down would show the same figure on every ad group
                 * beneath it, and an operator reading four ad groups at «100 ر.س / يوم» would conclude
                 * the campaign spends four hundred.
                 */
                'daily_budget' => null,
                'lifetime_budget' => null,
                'currency' => null,
                'targeting' => null, // criteria are separate resources; not fetched at this level
                'raw' => $row,
            ];
        }

        return $groups;
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<'GAQL'
            SELECT ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type,
                   ad_group_ad.ad.final_urls, ad_group_ad.status,
                   ad_group_ad.policy_summary.approval_status, ad_group.id, campaign.id
            FROM ad_group_ad
            WHERE ad_group_ad.status != 'REMOVED'
            GAQL);

        $ads = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $adGroupAd */
            $adGroupAd = (array) ($row['adGroupAd'] ?? []);
            /** @var array<string,mixed> $ad */
            $ad = (array) ($adGroupAd['ad'] ?? []);

            if (($ad['id'] ?? null) === null) {
                continue;
            }

            $finalUrls = array_values(array_filter((array) ($ad['finalUrls'] ?? []), 'is_string'));

            $ads[] = array_filter([
                'external_id' => (string) $ad['id'],
                'ad_set_external_id' => isset($row['adGroup']['id']) ? (string) $row['adGroup']['id'] : null,
                'campaign_external_id' => isset($row['campaign']['id']) ? (string) $row['campaign']['id'] : null,
                // Google's responsive ads are frequently unnamed; the id is the only honest label.
                'name' => (string) ($ad['name'] ?? $ad['id']),
                'status' => strtolower((string) ($adGroupAd['status'] ?? 'unknown')),
                'review_status' => match (strtoupper((string) (($adGroupAd['policySummary']['approvalStatus'] ?? '')))) {
                    'APPROVED' => 'approved',
                    'APPROVED_LIMITED', 'AREA_OF_INTEREST_ONLY' => 'pending',
                    'DISAPPROVED' => 'rejected',
                    default => null,
                },
                'destination_url' => $finalUrls[0] ?? null,
                /*
                 * Google has no creative object beneath an ad — the ad IS the creative. So there is no
                 * separate row to make, and inventing one per ad would double every ad in the panel
                 * for no information at all. The ad's type is kept in `raw`.
                 */
                'creative' => null,
                'raw' => $row,
            ], static fn ($v) => $v !== null);
        }

        return $ads;
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<GAQL
            SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks,
                   metrics.conversions, metrics.video_views, metrics.engagements
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
            GAQL);

        // The sales, asked for separately and by category — see the note on the method.
        $sales = $this->purchasesByCampaignDay($tokens, $adAccountId, $from, $to);

        $insights = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $campaign */
            $campaign = (array) ($row['campaign'] ?? []);
            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);
            /** @var array<string,mixed> $segments */
            $segments = (array) ($row['segments'] ?? []);

            $campaignId = (string) ($campaign['id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            $date = (string) ($segments['date'] ?? $from);
            $sale = $sales["{$campaignId}|{$date}"] ?? [];

            $insights[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => $date,
                'spend' => isset($metrics['costMicros']) ? (float) $metrics['costMicros'] / self::MICRO : null,
                'impressions' => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
                'clicks' => isset($metrics['clicks']) ? (float) $metrics['clicks'] : null,
                /*
                 * `metrics.conversions` is EVERY conversion action the account counts — a call, a
                 * form, a signup, a store visit, a sale. It is Google's own «conversions» figure and
                 * is carried as such, but it is not the sale, and `purchases` below comes from the
                 * PURCHASE category instead. Before GADS-001 the two were the same number and a
                 * lead-generation account read its enquiries as orders.
                 */
                'conversions' => isset($metrics['conversions']) ? (float) $metrics['conversions'] : null,
                'video_views' => isset($metrics['videoViews']) ? (float) $metrics['videoViews'] : null,
                'engagements' => isset($metrics['engagements']) ? (float) $metrics['engagements'] : null,
                'purchases' => $sale['purchases'] ?? null,
                'revenue' => $sale['revenue'] ?? null,
            ], static fn ($v) => $v !== null);
        }

        return $insights;
    }

    /**
     * Sales per campaign per day, from the PURCHASE conversion category alone (GADS-001).
     *
     * Google Ads has no purchase metric. It has `metrics.conversions`, which counts whichever
     * conversion ACTIONS the account has told it to count — a phone call, a form submission, a
     * newsletter signup, a store visit, a sale — and `metrics.conversions_value`, which is the value
     * assigned to all of them. This connector reported both as `conversions` and `revenue`, so a
     * lead-generation account read its enquiries as orders and whatever value it had assigned to a
     * lead as money taken.
     *
     * The only honest way to get sales out of this API is to ask for them by category, which is what
     * this second query does. It is a second round trip and worth it: the alternative is a purchase
     * figure that is right only for accounts that happen to count nothing else.
     *
     * The category is filtered in the QUERY and checked again HERE. Not distrust of Google — a guard
     * against this method being pointed at an unfiltered query later and silently counting
     * everything again, which is precisely the defect it exists to fix.
     *
     * A campaign with no purchase-category conversions appears in neither map, so `purchases` and
     * `revenue` are ABSENT for it rather than zero: an account that does not measure sales has not
     * measured zero sales.
     *
     * @return array<string, array{purchases: float, revenue: float}> keyed «campaignId|date»
     */
    private function purchasesByCampaignDay(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<GAQL
            SELECT campaign.id, segments.date, segments.conversion_action_category,
                   metrics.conversions, metrics.conversions_value
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
              AND segments.conversion_action_category = 'PURCHASE'
            GAQL);

        $sales = [];

        foreach ($rows as $row) {
            /** @var array<string,mixed> $segments */
            $segments = (array) ($row['segments'] ?? []);

            if ((string) ($segments['conversionActionCategory'] ?? '') !== 'PURCHASE') {
                continue;
            }

            $campaignId = (string) (((array) ($row['campaign'] ?? []))['id'] ?? '');

            if ($campaignId === '') {
                continue;
            }

            /** @var array<string,mixed> $metrics */
            $metrics = (array) ($row['metrics'] ?? []);
            $key = $campaignId.'|'.(string) ($segments['date'] ?? $from);

            // Several conversion ACTIONS can share the PURCHASE category — two checkout flows, web
            // and app. Those are different sales, so within the category they are added.
            $sales[$key] ??= ['purchases' => 0.0, 'revenue' => 0.0];
            $sales[$key]['purchases'] += (float) ($metrics['conversions'] ?? 0);
            $sales[$key]['revenue'] += (float) ($metrics['conversionsValue'] ?? 0);
        }

        return $sales;
    }

    /**
     * Run a GAQL query and flatten the stream.
     *
     * `searchStream` answers with a JSON ARRAY of chunks, each `{ results: [...] }`. Reading
     * `$body['results']` — the shape the non-streaming `search` endpoint returns — finds nothing at
     * all here, and an integration that returns no rows without an error is the hardest kind to
     * notice, because everything downstream looks like a quiet day.
     *
     * @return list<array<string,mixed>>
     */
    private function stream(OAuthTokens $tokens, string $customerId, string $query): array
    {
        $customer = $this->plainCustomerId($customerId);

        $response = $this->api($tokens)->post($this->url("customers/{$customer}/googleAds:searchStream"), [
            'query' => trim($query),
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                $this->label().' refused the query: '.PlatformHttp::reason($response),
            );
        }

        /** @var array<int,array<string,mixed>> $chunks */
        $chunks = $response->json() ?? [];

        // Recorded by hand because a stream is a JSON ARRAY, so it cannot go through `read()` — which
        // takes an object — and a platform whose payloads were never retained would be the one nobody
        // could audit (INTEG-RAW-001).
        $this->rawResponses[] = ['stream' => $chunks];

        $results = [];

        foreach ($chunks as $chunk) {
            foreach ((array) ($chunk['results'] ?? []) as $row) {
                /** @var array<string,mixed> $row */
                $results[] = $row;
            }
        }

        return $results;
    }

    /** One extra query for the human name of a customer; a failure is not worth failing a listing over. */
    private function descriptiveName(OAuthTokens $tokens, string $customerId): ?string
    {
        try {
            $rows = $this->stream($tokens, $customerId, 'SELECT customer.descriptive_name FROM customer LIMIT 1');
        } catch (\Throwable) {
            return null;
        }

        $name = $rows[0]['customer']['descriptiveName'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /** Google shows `123-456-7890` everywhere and accepts only `1234567890`. */
    private function plainCustomerId(string $id): string
    {
        return preg_replace('/\D+/', '', $id) ?? $id;
    }
}
