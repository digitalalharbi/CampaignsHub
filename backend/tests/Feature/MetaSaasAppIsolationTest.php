<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * META-SAAS-APP-001 — a THIRD Meta app, independent of Live and of the Candidate.
 *
 *  - its credentials live in their own row and never borrow or overwrite a Live or Candidate value;
 *  - a customer connection it issues is a SEPARATE connection, bound to it for life: the Live connection's tokens are untouched, and the
 *    SaaS token is refreshed and used with the SaaS app's own App ID and secret;
 *  - new connections use it only after an admin chose so, which needs a passed test with the current credentials;
 *  - an existing account moves to it only explicitly, keeps its row (bindings, history) and the old connection stays connected.
 */
final class MetaSaasAppIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_ID = 'live-app-111111';

    private const LIVE_SECRET = 'live-secret-AAAAAAAAAAAA';

    private const SAAS_ID = 'saas-app-777777';

    private const SAAS_SECRET = 'saas-secret-CCCCCCCCCCCC';

    private const SAAS_CONFIG = 'cfg-888888';

    private User $owner;

    private User $platformOwner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'saas-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@saas.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $this->platformOwner = User::create(['name' => 'P', 'email' => 'p@saas.test', 'password' => 'secret123']);
        $this->platformOwner->forceFill(['is_platform_admin' => true])->save();

        config()->set('ad_platforms.platforms.meta.client_id', self::LIVE_ID);
        config()->set('ad_platforms.platforms.meta.client_secret', self::LIVE_SECRET);
    }

    private function configureSaas(): void
    {
        $this->actingAs($this->platformOwner, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas', [
            'client_id' => self::SAAS_ID, 'client_secret' => self::SAAS_SECRET, 'config_id' => self::SAAS_CONFIG,
        ])->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
    }

    /** @return array<string,string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    private function liveConnection(): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('LIVE-TOKEN', null, Carbon::now()->addDays(50)), connectionName: 'Meta Marketing API',
        );
    }

    public function test_the_saas_app_keeps_its_own_credentials_and_never_touches_live_or_candidate(): void
    {
        $this->assertFalse(PlatformCredentials::forMeta(MetaCredentialProfile::Saas)->isConfigured());
        $this->configureSaas();

        $saas = PlatformCredentials::forMeta(MetaCredentialProfile::Saas);
        $this->assertSame(self::SAAS_ID, $saas->get('client_id'));
        $this->assertSame(self::LIVE_ID, PlatformCredentials::forMeta(MetaCredentialProfile::Live)->get('client_id'));
        $this->assertNull(app(MetaCandidateCredentials::class)->value('client_id'), 'the Candidate store is untouched');
        $this->assertNull(ProviderConfiguration::query()->where('provider', 'meta')->first(), 'no Live row was written');
        $this->assertNotNull(ProviderConfiguration::query()->where('provider', 'meta.saas')->first());
    }

    public function test_a_saas_login_opens_its_own_connection_and_leaves_the_live_connection_and_token_alone(): void
    {
        $live = $this->liveConnection();
        $liveToken = app(TokenVault::class)->stored($live)->accessToken;
        $this->configureSaas();

        $url = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'saas'])
            ->assertOk()->assertJsonPath('data.app_profile', 'saas')->json('data.authorization_url');
        $q = $this->query((string) $url);
        $this->assertSame(self::SAAS_ID, $q['client_id'], 'the SaaS dialog names the SaaS App ID');

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'SAAS-TOKEN', 'expires_in' => 5184000]),
            'graph.facebook.com/*' => Http::response(['data' => []]),
        ]);
        $this->get('/api/v1/oauth/ads/meta/callback?'.http_build_query(['state' => $q['state'], 'code' => 'abc']))->assertRedirect();

        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), 'oauth/access_token')
            && str_contains($r->url().$r->body(), self::SAAS_SECRET) && ! str_contains($r->url().$r->body(), self::LIVE_SECRET));

        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->get();
        $this->assertCount(2, $connections, 'the SaaS login opened its OWN connection');
        $saas = $connections->firstWhere('app_profile', 'saas');
        $this->assertNotNull($saas);
        $this->assertSame('SAAS-TOKEN', app(TokenVault::class)->stored($saas)->accessToken);
        $this->assertSame($liveToken, app(TokenVault::class)->stored($live->refresh())->accessToken, 'the Live token was not overwritten');
        $this->assertNull($live->app_profile);
    }

    public function test_each_connection_is_refreshed_and_called_with_the_app_that_issued_it(): void
    {
        $this->configureSaas();
        $live = $this->liveConnection();
        $saas = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('OLD', null, Carbon::now()->subMinute()),
            connectionName: 'SaaS', appProfile: MetaCredentialProfile::Saas,
        );

        $this->assertSame(self::SAAS_ID, PlatformCredentials::forConnection($saas)->get('client_id'));
        $this->assertSame(self::LIVE_ID, PlatformCredentials::forConnection($live)->get('client_id'));

        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'NEW', 'expires_in' => 5184000])]);
        app(TokenVault::class)->fresh($saas);

        Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url().$r->body(), self::SAAS_ID) && ! str_contains($r->url().$r->body(), self::LIVE_ID));
    }

    public function test_new_connections_use_the_saas_app_only_after_a_passed_test_with_the_current_credentials(): void
    {
        $this->configureSaas();
        $admin = $this->actingAs($this->platformOwner, 'sanctum');

        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/routing', ['default' => 'saas', 'confirm' => true])->assertStatus(409);

        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->platformOwner->id, 'status' => 'succeeded',
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
        // A succeeded Candidate run never counts for the SaaS app.
        $this->assertSame('saas', MetaCandidateConnection::latestRun(MetaCredentialProfile::Saas)?->profile);
        $this->assertNull(MetaCandidateConnection::latestRun(MetaCredentialProfile::Candidate));

        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/routing', ['default' => 'saas', 'confirm' => true])->assertOk()
            ->assertJsonPath('data.routing.default_for_new_connections', 'saas');

        $start = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start')->assertOk();
        $this->assertSame('saas', $start->json('data.app_profile'));
        $this->assertSame('live', $this->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'live'])->json('data.app_profile'));

        // Changing the credentials withdraws the default until the next passed test.
        $this->actingAs($this->platformOwner, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas', ['client_secret' => 'rotated-secret-DDDD'])->assertOk()
            ->assertJsonPath('data.routing.default_for_new_connections', 'live');
    }

    public function test_a_tenant_cannot_choose_the_candidate_app_and_the_routing_endpoint_is_platform_admin_only(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'candidate'])->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/admin/settings/integrations/meta-saas/routing', ['default' => 'saas', 'confirm' => true])->assertForbidden();

        $this->expectException(\InvalidArgumentException::class);
        AuthorizationState::issue((string) $this->tenant->id, 'meta', metaProfile: MetaCredentialProfile::Candidate);
    }

    public function test_an_account_moves_to_the_saas_app_only_explicitly_keeps_its_row_and_can_move_back(): void
    {
        $this->configureSaas();
        $live = $this->liveConnection();
        $saas = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('S', null, Carbon::now()->addDays(50)),
            connectionName: 'SaaS', appProfile: MetaCredentialProfile::Saas,
        );
        $make = fn (ProviderConnection $c): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $c->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'act_1', 'name' => 'Nasl', 'currency' => 'USD', 'status' => 'active',
        ]);
        $account = $make($live);
        $as = $this->actingAs($this->owner, 'sanctum');

        $as->postJson("/api/v1/accounts/{$account->id}/move-to-connection", ['connection_id' => $saas->id, 'confirm' => true])
            ->assertStatus(422); // the SaaS app has not discovered it

        $make($saas);
        $as->postJson("/api/v1/accounts/{$account->id}/move-to-connection", ['connection_id' => $saas->id, 'confirm' => true])
            ->assertOk()->assertJsonPath('data.previous_connection_still_connected', true);

        $this->assertSame((string) $saas->id, (string) $account->refresh()->provider_connection_id, 'the SAME row moved — bindings and history keep their account id');
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('external_id', 'act_1')->count(), 'the discovery twin was folded, not duplicated');
        $this->assertSame('connected', $live->refresh()->status, 'the old connection was neither revoked nor disconnected');

        $as->postJson("/api/v1/accounts/{$account->id}/move-to-connection", ['connection_id' => $live->id, 'confirm' => true])->assertOk();
        $this->assertSame((string) $live->id, (string) $account->refresh()->provider_connection_id);
    }
}
