<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-APP-SWITCH-GUARD-001 — promote and rollback may not strand a connection whose token only the current Live app can refresh.
 *
 * Meta tokens are app-scoped and every Live connection is refreshed with whatever identity Live has now. Switching that identity away
 * from the app that issued a token stops the connection syncing until its owner reconnects. The issuer is read from the connection
 * (`issuing_app_id`), never inferred from its date; a connection with no recorded issuer counts as the current app's.
 */
final class MetaAppSwitchGuardTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/settings/integrations/meta-candidate';

    private const LIVE = 'live-app-111111';

    private const CAND = 'cand-app-222222';

    private const SWITCH = ['confirm' => true, 'confirm_narrow_scopes' => true, 'acknowledge_app_switch' => true];

    private User $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'P', 'email' => 'p@guard.test', 'password' => 'secret123']);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'guard-'.uniqid(), 'status' => 'active']);

        config()->set('ad_platforms.platforms.meta.client_id', self::LIVE);
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAAAAAAAAAA');
        config()->set('ad_platforms.meta_candidate.client_id', self::CAND);
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
        $url = $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/test')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/ads/meta/callback?code=abc&state={$q['state']}")->assertRedirectContains('outcome=succeeded');
    }

    /** Promote with nobody connected yet: Live becomes the candidate and the previous app is the rollback point. */
    private function promoted(): void
    {
        $this->passRoundTrip();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/promote', self::SWITCH)->assertOk();
        $this->assertSame(self::CAND, $this->liveAppId());
    }

    /** A customer connection opened through the Live app as it is now — the way every real one is. */
    private function connect(string $token = 'CUSTOMER-TOKEN', ?MetaCredentialProfile $profile = null, ?string $workspace = null): ProviderConnection
    {
        app(ProviderConfigurationService::class)->forgetCache();

        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens($token, null, Carbon::now()->addDays(50)),
            connectionName: 'Meta', clientWorkspaceId: $workspace, appProfile: $profile,
        );
    }

    private function liveAppId(): ?string
    {
        app(ProviderConfigurationService::class)->forgetCache();

        return PlatformCredentials::forMeta(MetaCredentialProfile::Live)->get('client_id');
    }

    public function test_a_connection_records_the_app_that_issued_its_token_and_the_app_that_refreshed_it(): void
    {
        $connection = $this->connect();
        $this->assertSame(self::LIVE, $connection->fresh()->issuing_app_id);

        $connection->forceFill(['issuing_app_id' => null])->save();
        app(TokenVault::class)->store($connection, new OAuthTokens('REFRESHED', null, Carbon::now()->addDays(50)));
        $this->assertSame(self::LIVE, $connection->fresh()->issuing_app_id, 'a refresh proves the token is the refreshing app\'s');
    }

    public function test_rollback_is_refused_while_a_connection_holds_a_token_the_current_app_issued(): void
    {
        $this->promoted();
        $connection = $this->connect();
        $this->assertSame(self::CAND, $connection->issuing_app_id);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true, 'acknowledge_app_switch' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.rollback.0', 'connections_issued_by_current_app')
            ->assertJsonPath('meta.guard.affected', 1)
            ->assertJsonPath('meta.guard.current_app_hint', mb_substr(self::CAND, -4))
            ->assertJsonPath('meta.guard.target_app_hint', mb_substr(self::LIVE, -4));

        $this->assertStringNotContainsString('CUSTOMER-TOKEN', $res->getContent());
        $this->assertStringNotContainsString('secret', $res->getContent());
        $this->assertSame(self::CAND, $this->liveAppId(), 'Live is unchanged');
        $this->assertTrue(ProviderConfiguration::query()->where('provider', 'meta.previous_live')->exists(), 'the rollback point is not spent');
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('CUSTOMER-TOKEN', app(TokenVault::class)->stored($connection->fresh())->accessToken);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta.rollback_refused']);
    }

    public function test_a_connection_with_no_recorded_issuer_counts_as_the_current_apps(): void
    {
        $this->promoted();
        $this->connect()->forceFill(['issuing_app_id' => null])->save();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true, 'acknowledge_app_switch' => true])
            ->assertStatus(409)
            ->assertJsonPath('meta.guard.affected', 1)
            ->assertJsonPath('meta.guard.unverified', 1);
        $this->assertSame(self::CAND, $this->liveAppId());
    }

    public function test_the_admin_page_shows_why_rollback_is_blocked_and_for_how_many_connections(): void
    {
        $this->promoted();
        $this->connect();
        $this->connect('SECOND', workspace: null)->forceFill(['issuing_app_id' => null])->save();

        $this->actingAs($this->admin, 'sanctum')->getJson(self::BASE)->assertOk()
            ->assertJsonPath('data.promotion.rollback_guard.safe', false)
            ->assertJsonPath('data.promotion.rollback_guard.reason', 'connections_issued_by_current_app')
            ->assertJsonPath('data.promotion.rollback_guard.affected', ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->count());
    }

    public function test_rollback_is_allowed_when_no_active_connection_depends_on_the_current_app(): void
    {
        $this->promoted();
        // Revoked: nothing to strand.
        $this->connect()->forceFill(['status' => 'revoked'])->save();

        $this->actingAs($this->admin, 'sanctum')->getJson(self::BASE)->assertJsonPath('data.promotion.rollback_guard.safe', true);
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true, 'acknowledge_app_switch' => true])->assertOk();
        $this->assertSame(self::LIVE, $this->liveAppId());
    }

    public function test_a_connection_the_rollback_target_issued_is_not_stranded_by_returning_to_it(): void
    {
        $this->promoted();
        $this->connect()->forceFill(['issuing_app_id' => self::LIVE])->save();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true, 'acknowledge_app_switch' => true])->assertOk();
        $this->assertSame(self::LIVE, $this->liveAppId());
    }

    public function test_promotion_is_refused_while_a_connection_holds_a_token_the_current_live_app_issued(): void
    {
        $connection = $this->connect();
        $this->passRoundTrip();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/promote', self::SWITCH)
            ->assertStatus(409)
            ->assertJsonPath('errors.promotion.0', 'connections_issued_by_current_app')
            ->assertJsonPath('meta.guard.affected', 1);

        $this->assertSame(self::LIVE, $this->liveAppId());
        $this->assertFalse(ProviderConfiguration::query()->where('provider', 'meta.previous_live')->exists());
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_saas_connections_have_their_own_app_and_never_block_or_move(): void
    {
        $this->promoted();
        $saas = $this->connect('SAAS-TOKEN', MetaCredentialProfile::Saas);
        $before = $saas->fresh()->toArray();

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true, 'acknowledge_app_switch' => true])->assertOk();

        $this->assertSame($before, $saas->fresh()->toArray());
        $this->assertSame('SAAS-TOKEN', app(TokenVault::class)->stored($saas->fresh())->accessToken);
    }

    public function test_every_switch_needs_the_named_acknowledgement(): void
    {
        $this->passRoundTrip();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/promote', ['confirm' => true, 'confirm_narrow_scopes' => true])
            ->assertStatus(422)->assertJsonValidationErrors('acknowledge_app_switch');
        $this->assertSame(self::LIVE, $this->liveAppId());

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/promote', self::SWITCH)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/rollback', ['confirm' => true])
            ->assertStatus(422)->assertJsonValidationErrors('acknowledge_app_switch');
        $this->assertSame(self::CAND, $this->liveAppId());
    }
}
