<?php

declare(strict_types=1);

namespace Tests\Support\Providers;

use App\Domains\Integrations\Catalogue\ProviderAuth;
use App\Domains\Integrations\Catalogue\ProviderCapabilities;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Catalogue\ResourceType;
use App\Domains\Integrations\Catalogue\WebhookSupport;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Http\Client\PendingRequest;

/**
 * INTEG-CAPABILITY-CONTRACT-001 §5 gate 5 — a provider this product has never heard of, in the tests
 * directory, which is the only place it exists.
 *
 * It is the worked example in `docs/integrations/ADDING_A_PROVIDER.md`: the three things a real provider
 * needs, written the way a real one writes them. It deliberately declares almost nothing. No parent, no
 * webhooks, no refresh, no grains: the point is that an adapter claiming nothing still goes through
 * discovery, binding and a sync.
 *
 * It talks to a made-up host over HTTP, so `Http::fake()` stands where the provider would.
 */
final class FakeAdsConnector extends ApiAdvertisingConnector
{
    public const KEY = 'fakeads';

    private const API = 'https://api.fakeads.test/v1';

    /** The catalogue entry. A real provider writes this as a private method in `ProviderCatalogue`. */
    public static function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: self::KEY,
            kind: ProviderKind::Advertising,
            label: 'Fake Ads API',
            labelAr: 'واجهة إعلانات تجريبية',
            fields: [],
            scopes: [],
            usesPkce: false,
            supportsRefresh: false,
            tokenNote: 'A bearer key scoped to one advertiser.',
            tokenNoteAr: 'مفتاح لمعلن واحد.',
            webhooks: WebhookSupport::PollingOnly,
            webhookSignatureHeader: null,
            prerequisites: [],
            prerequisitesAr: [],
            docsUrl: 'https://api.fakeads.test/docs',
            rateLimitNote: 'Refused with HTTP 429.',
            paginationNote: 'One page.',
            capabilities: new ProviderCapabilities(
                resourceType: ResourceType::AdAccount,
                accountType: 'ad_account',
            ),
            auth: ProviderAuth::ApiKey,
        );
    }

    /** The protocol half. A real provider writes this into `config/ad_platforms.php`. */
    public static function config(): array
    {
        return ['label' => 'Fake Ads API', 'authorize_url' => '', 'token_url' => '', 'api_base' => self::API];
    }

    protected function platform(): string
    {
        return self::KEY;
    }

    protected function fetchAdAccounts(OAuthTokens $tokens): array
    {
        $body = $this->read($this->client($tokens)->get(self::API.'/accounts'), 'ad accounts');

        return array_map(static fn (array $a): array => [
            'external_id' => (string) $a['id'],
            'name' => (string) $a['name'],
            'currency' => (string) $a['currency'],
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
            'parent_external_id' => null,
            'raw' => $a,
        ], (array) ($body['accounts'] ?? []));
    }

    protected function fetchCampaigns(OAuthTokens $tokens, string $adAccountId): array
    {
        $body = $this->read($this->client($tokens)->get(self::API."/accounts/{$adAccountId}/campaigns"), 'campaigns');

        return array_map(static fn (array $c): array => [
            'external_id' => (string) $c['id'],
            'name' => (string) $c['name'],
            'objective' => null,
            'status' => 'active',
            'daily_budget' => null,
            'lifetime_budget' => null,
            'currency' => null,
            'start_at' => null,
            'end_at' => null,
            'raw' => $c,
        ], (array) ($body['campaigns'] ?? []));
    }

    protected function fetchAdSets(OAuthTokens $tokens, string $adAccountId): array
    {
        return [];
    }

    protected function fetchAds(OAuthTokens $tokens, string $adAccountId): array
    {
        return [];
    }

    protected function fetchInsights(OAuthTokens $tokens, string $adAccountId, string $from, string $to): array
    {
        $body = $this->read($this->client($tokens)->get(self::API."/accounts/{$adAccountId}/insights", ['from' => $from, 'to' => $to]), 'insights');

        return array_map(function (array $r): array {
            $this->rawInsightRows++;

            return [
                'campaign_id' => (string) $r['campaign'],
                'date' => (string) $r['day'],
                'spend' => (float) $r['spend'],
                'impressions' => (float) $r['impressions'],
                'clicks' => (float) $r['clicks'],
            ];
        }, (array) ($body['rows'] ?? []));
    }

    private function client(OAuthTokens $tokens): PendingRequest
    {
        return PlatformHttp::client(self::KEY)->withToken($tokens->accessToken);
    }
}
