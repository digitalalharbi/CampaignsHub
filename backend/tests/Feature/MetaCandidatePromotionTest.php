<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-CANDIDATE-001 part 3 — Candidate → Live and back, prepared and never automatic.
 */
final class MetaCandidatePromotionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/settings/integrations/meta-candidate';

    private User $platformOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformOwner = User::create(['name' => 'P', 'email' => 'p@platform.test', 'password' => 'secret123']);
        $this->platformOwner->forceFill(['is_platform_admin' => true])->save();

        // Live configured from the ENVIRONMENT, as production may be.
        config()->set('ad_platforms.platforms.meta.client_id', 'live-app-111111');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAAAAAAAAAA');
        config()->set('ad_platforms.meta_candidate.client_id', 'cand-app-222222');
        config()->set('ad_platforms.meta_candidate.client_secret', 'cand-secret-BBBBBBBBBBBB');
        config()->set('ad_platforms.meta_candidate.config_id', 'cfg-333333');
    }

    private function passRoundTrip(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'CAND-TOKEN', 'expires_in' => 5184000]),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [['id' => 'act_9', 'name' => 'A', 'account_status' => 1, 'currency' => 'SAR']]]),
            'graph.facebook.com/*/act_9/insights*' => Http::response(['data' => []]),
        ]);

        $url = $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/test')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$q['state']}")->assertRedirectContains('outcome=succeeded');
    }

    private function liveConnection(Tenant $tenant): ProviderConnection
    {
        $credential = new IntegrationCredential;
        $credential->forceFill([
            'tenant_id' => $tenant->id, 'provider' => 'meta', 'credential_scope' => 'tenant_shared',
            'credential_type' => 'oauth2', 'encrypted_payload' => json_encode(['access_token' => 'OLD-APP-TOKEN']), 'status' => 'active',
        ])->save();
        $connection = new ProviderConnection;
        $connection->forceFill([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'Meta', 'scope' => 'tenant_shared', 'status' => 'connected',
        ])->save();

        return $connection;
    }

    private function live(): PlatformCredentials
    {
        app(ProviderConfigurationService::class)->forgetCache();

        return PlatformCredentials::for('meta');
    }

    public function test_promotion_is_refused_without_a_successful_round_trip(): void
    {
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])
            ->assertStatus(409)->assertJsonPath('errors.promotion.0', 'latest_round_trip_not_succeeded');

        // A failed run does not license it either.
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no', 'code' => 1]], 400)]);
        $url = $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/test')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$q['state']}");

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])->assertStatus(409);
        $this->assertSame('live-app-111111', $this->live()->get('client_id'));
    }

    public function test_promotion_is_refused_when_the_candidate_changed_after_its_test(): void
    {
        $this->passRoundTrip();
        config()->set('ad_platforms.meta_candidate.client_secret', 'rotated-after-the-test-XXXX');
        app(MetaCandidateCredentials::class)->forgetCache();

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])
            ->assertStatus(409)->assertJsonPath('errors.promotion.0', 'credentials_changed_since_round_trip');
    }

    public function test_promotion_needs_the_platform_owner_and_an_explicit_confirmation(): void
    {
        $this->passRoundTrip();
        $tenantUser = User::create(['name' => 'T', 'email' => 't@agency.test', 'password' => 'secret123']);

        $this->actingAs($tenantUser, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])->assertForbidden();
        $this->actingAs($tenantUser, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])->assertForbidden();
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote')->assertStatus(422);

        $this->assertSame('live-app-111111', $this->live()->get('client_id'));
    }

    public function test_promotion_switches_the_live_app_keeps_the_previous_one_and_touches_no_connection(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $connection = $this->liveConnection($tenant);
        $before = [ProviderConnection::withoutGlobalScopes()->get()->toArray(), IntegrationCredential::withoutGlobalScopes()->get()->map->revealPayload()->all()];

        $this->passRoundTrip();

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.promotion.rollback_available', true)
            ->assertJsonPath('data.promotion.reason', 'candidate_already_live');

        $live = $this->live();
        $this->assertSame('cand-app-222222', $live->get('client_id'));
        $this->assertSame('cand-secret-BBBBBBBBBBBB', $live->get('client_secret'));
        $this->assertSame('cfg-333333', $live->get('config_id'));
        $this->assertSame(['ads_read'], $live->scopes());

        // The Live dialog is now the FLfB configuration.
        parse_str((string) parse_url(app(PlatformOAuth::class)->authorizationUrl($live, 's'), PHP_URL_QUERY), $q);
        $this->assertSame('cfg-333333', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q);

        // No customer connection, token or credential moved.
        $this->assertSame($before, [ProviderConnection::withoutGlobalScopes()->get()->toArray(), IntegrationCredential::withoutGlobalScopes()->get()->map->revealPayload()->all()]);
        $this->assertSame('connected', $connection->fresh()->status);

        $this->assertTrue(ProviderConfiguration::query()->where('provider', 'meta.previous_live')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta.promoted']);
        $this->assertStringNotContainsString('cand-secret', (string) DB::table('audit_logs')->where('action', 'platform.integration.meta.promoted')->value('after'));
    }

    public function test_rollback_restores_the_previous_live_app_exactly_and_only_once(): void
    {
        $this->passRoundTrip();
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])->assertOk();

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])
            ->assertOk()->assertJsonPath('data.promotion.rollback_available', false);

        $live = $this->live();
        $this->assertSame('live-app-111111', $live->get('client_id'));
        $this->assertSame('live-secret-AAAAAAAAAAAA', $live->get('client_secret'));
        $this->assertNull($live->get('config_id'));
        $this->assertSame(['ads_read', 'ads_management', 'business_management'], $live->scopes());
        // Env-configured before, env-configured again: no leftover row claims otherwise.
        $this->assertFalse(ProviderConfiguration::query()->where('provider', 'meta')->exists());
        $this->assertFalse(ProviderConfiguration::query()->where('provider', 'meta.previous_live')->exists());

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta.rolled_back']);
    }

    public function test_rollback_restores_stored_live_values_and_keeps_the_webhook_fields(): void
    {
        $settings = app(ProviderConfigurationService::class);
        $settings->save('meta', ['client_id' => 'stored-live-4444', 'client_secret' => 'stored-live-secret-5555', 'webhook_verify_token' => 'verify-me']);
        ProviderConfiguration::query()->where('provider', 'meta')->update(['scopes' => json_encode(['ads_read', 'ads_management']), 'last_test_status' => 'passed']);
        $settings->forgetCache();

        $this->passRoundTrip();
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])->assertOk();
        $this->assertSame('verify-me', $this->live()->get('webhook_verify_token'), 'Promotion leaves the webhook fields.');
        $this->assertNull(ProviderConfiguration::query()->where('provider', 'meta')->value('last_test_status'));

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])->assertOk();

        $live = $this->live();
        $this->assertSame('stored-live-4444', $live->get('client_id'));
        $this->assertSame('stored-live-secret-5555', $live->get('client_secret'));
        $this->assertNull($live->get('config_id'));
        $this->assertSame('verify-me', $live->get('webhook_verify_token'));
        $this->assertSame(['ads_read', 'ads_management'], $live->scopes());
        $this->assertSame('passed', ProviderConfiguration::query()->where('provider', 'meta')->value('last_test_status'));
    }

    public function test_a_second_promotion_of_the_same_candidate_is_refused(): void
    {
        $this->passRoundTrip();
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])->assertOk();

        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true])
            ->assertStatus(409)->assertJsonPath('errors.promotion.0', 'candidate_already_live');
        // The rollback point still names the ORIGINAL Live app.
        $this->actingAs($this->platformOwner, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])->assertOk();
        $this->assertSame('live-app-111111', $this->live()->get('client_id'));
    }
}
