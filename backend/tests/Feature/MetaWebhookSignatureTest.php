<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationWebhookEvent;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Webhooks\WebhookSignature;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * META-MULTI-APP-001 — an inbound Meta webhook is verified with the secret of the app the ENDPOINT belongs to, and with no other.
 * `/webhooks/ads/meta` is the Live app exactly as before; `/webhooks/ads/meta/saas` is the SaaS app. Secrets are fixtures.
 */
final class MetaWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = '{"entry":[{"id":"act_1"}],"event_id":"e-1"}';

    private const LIVE = '/api/v1/webhooks/ads/meta';

    private const SAAS = '/api/v1/webhooks/ads/meta/saas';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ad_platforms.platforms.meta.client_id', 'live-id');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAA');
    }

    private function configureSaas(string $secret = 'saas-secret-BBBB'): void
    {
        app(MetaSaasCredentials::class)->save(['client_id' => '2604968763258530', 'client_secret' => $secret, 'config_id' => '1138211818567426']);
        app(MetaSaasCredentials::class)->forgetCache();
    }

    private function sig(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    private function deliver(string $url, string $body, ?string $secret): TestResponse
    {
        return $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            ...($secret === null ? [] : ['HTTP_X_HUB_SIGNATURE_256' => $this->sig($body, $secret)]),
        ], $body);
    }

    public function test_each_endpoint_accepts_only_its_own_apps_secret(): void
    {
        $this->configureSaas();

        $this->deliver(self::SAAS, self::BODY, 'saas-secret-BBBB')->assertOk();
        $this->deliver(self::LIVE, self::BODY, 'live-secret-AAAA')->assertOk();
        $this->assertSame(2, IntegrationWebhookEvent::query()->count(), 'one event per app: the same body is not deduped across apps');
        $this->assertSame('saas', app(WebhookSignature::class)->check('meta', self::BODY, $this->sig(self::BODY, 'saas-secret-BBBB'), 'saas')['app']);
        $this->assertSame('live', app(WebhookSignature::class)->check('meta', self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'))['app']);
    }

    public function test_a_body_signed_by_the_other_apps_secret_is_refused_on_both_endpoints_and_stores_nothing(): void
    {
        $this->configureSaas();

        $this->deliver(self::LIVE, self::BODY, 'saas-secret-BBBB')->assertStatus(401);
        $this->deliver(self::SAAS, self::BODY, 'live-secret-AAAA')->assertStatus(401);
        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    public function test_unsigned_tampered_and_unknown_secret_deliveries_are_refused_on_both_endpoints(): void
    {
        $this->configureSaas();

        foreach ([self::LIVE, self::SAAS] as $url) {
            $this->deliver($url, self::BODY, null)->assertStatus(401);
            $this->deliver($url, self::BODY, 'unknown-secret')->assertStatus(401);
            $this->call('POST', $url, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $this->sig(self::BODY, $url === self::LIVE ? 'live-secret-AAAA' : 'saas-secret-BBBB'),
            ], self::BODY.' ')->assertStatus(401);
        }
        $this->assertDatabaseCount('integration_webhook_events', 0);
    }

    public function test_with_the_saas_app_unconfigured_its_endpoint_refuses_everything_and_live_is_unaffected(): void
    {
        $this->deliver(self::SAAS, self::BODY, 'saas-secret-BBBB')->assertStatus(401);
        $this->deliver(self::SAAS, self::BODY, 'live-secret-AAAA')->assertStatus(401);
        $this->deliver(self::SAAS, self::BODY, null)->assertStatus(401);
        $this->deliver(self::LIVE, self::BODY, 'live-secret-AAAA')->assertOk();
    }

    public function test_two_apps_with_an_identical_secret_each_endpoint_verifies_only_its_own_app(): void
    {
        $this->configureSaas('live-secret-AAAA');

        $this->deliver(self::LIVE, self::BODY, 'live-secret-AAAA')->assertOk();
        $this->deliver(self::SAAS, self::BODY, 'live-secret-AAAA')->assertOk();

        // Each delivery is attributed to its endpoint's app (the SaaS one carries the app in its dedupe key) and neither is the other's duplicate.
        $fingerprints = IntegrationWebhookEvent::query()->pluck('fingerprint')->all();
        $this->assertCount(2, $fingerprints);
        $this->assertContains(hash('sha256', 'saas|'.hash('sha256', 'e-1')), $fingerprints, 'the SaaS delivery carries its app in the dedupe key');
        $this->assertContains(hash('sha256', 'e-1'), $fingerprints, 'the Live key is exactly what it always was');
        $this->assertSame('saas', app(WebhookSignature::class)->check('meta', self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'), 'saas')['app']);
        $this->assertSame('live', app(WebhookSignature::class)->check('meta', self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'), null)['app']);
        // An unknown endpoint app verifies nothing.
        $this->assertFalse(app(WebhookSignature::class)->check('meta', self::BODY, $this->sig(self::BODY, 'live-secret-AAAA'), 'candidate')['verified']);
    }

    public function test_a_replayed_delivery_is_processed_once_on_each_endpoint(): void
    {
        $this->configureSaas();

        foreach ([[self::LIVE, 'live-secret-AAAA'], [self::SAAS, 'saas-secret-BBBB']] as [$url, $secret]) {
            $this->deliver($url, self::BODY, $secret)->assertOk();
            $this->deliver($url, self::BODY, $secret)->assertOk();
        }

        $this->assertSame(2, IntegrationWebhookEvent::query()->count(), 'the unique (provider, fingerprint) index deduped each replay');
    }

    public function test_a_delivery_on_the_saas_endpoint_matches_only_accounts_held_by_saas_connections(): void
    {
        $this->configureSaas();
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'wh-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $live = app(TokenVault::class)->open(tenantId: (string) $tenant->id, provider: 'meta', tokens: new OAuthTokens('T', null, Carbon::now()->addDays(5)), connectionName: 'x');
        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $live->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_1', 'name' => 'n', 'currency' => 'USD', 'status' => 'active',
        ]);

        $this->deliver(self::SAAS, self::BODY, 'saas-secret-BBBB')->assertOk();
        $this->assertNull(IntegrationWebhookEvent::query()->sole()->external_account_id, 'a SaaS delivery never attaches to a Live connection\'s account');
        $this->assertSame('unmatched', IntegrationWebhookEvent::query()->sole()->status);

        $this->deliver(self::LIVE, str_replace('e-1', 'e-2', self::BODY), 'live-secret-AAAA')->assertOk();
        $this->assertNotNull(IntegrationWebhookEvent::query()->where('fingerprint', hash('sha256', 'e-2'))->sole()->external_account_id);
    }

    public function test_the_saas_handshake_is_refused_until_the_app_has_its_own_verify_token_and_live_is_unchanged(): void
    {
        $this->configureSaas();

        $this->get(self::SAAS.'?hub_verify_token=anything&hub_challenge=123')->assertStatus(403);
        $this->get(self::LIVE.'?hub_verify_token=anything&hub_challenge=123')->assertStatus(403);
    }

    /** Meta's webhook spec carries no signed timestamp, so there is NO age limit: a late redelivery is deduped, never rejected. */
    public function test_a_redelivery_hours_later_is_accepted_and_processed_once_not_rejected_by_age(): void
    {
        $this->configureSaas();

        foreach ([[self::LIVE, 'live-secret-AAAA'], [self::SAAS, 'saas-secret-BBBB']] as [$url, $secret]) {
            $this->deliver($url, self::BODY, $secret)->assertOk();
        }
        $this->travel(7)->hours();
        foreach ([[self::LIVE, 'live-secret-AAAA'], [self::SAAS, 'saas-secret-BBBB']] as [$url, $secret]) {
            $this->deliver($url, self::BODY, $secret)->assertOk();
        }
        $this->travel(3)->days();
        $this->deliver(self::LIVE, self::BODY, 'live-secret-AAAA')->assertOk();

        $this->assertSame(2, IntegrationWebhookEvent::query()->count());
    }
}
