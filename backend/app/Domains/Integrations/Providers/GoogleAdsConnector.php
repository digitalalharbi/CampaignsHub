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

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $rows = $this->stream($tokens, $adAccountId, <<<GAQL
            SELECT campaign.id, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks,
                   metrics.conversions, metrics.conversions_value
            FROM campaign
            WHERE segments.date BETWEEN '{$from}' AND '{$to}'
            GAQL);

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

            $insights[] = array_filter([
                'campaign_id' => $campaignId,
                'date' => (string) ($segments['date'] ?? $from),
                'spend' => isset($metrics['costMicros']) ? (float) $metrics['costMicros'] / self::MICRO : null,
                'impressions' => isset($metrics['impressions']) ? (float) $metrics['impressions'] : null,
                'clicks' => isset($metrics['clicks']) ? (float) $metrics['clicks'] : null,
                'conversions' => isset($metrics['conversions']) ? (float) $metrics['conversions'] : null,
                'revenue' => isset($metrics['conversionsValue']) ? (float) $metrics['conversionsValue'] : null,
            ], static fn ($v) => $v !== null);
        }

        return $insights;
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
