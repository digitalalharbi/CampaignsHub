<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\MetaCandidate\MetaAppRouting;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasTestWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * META-SAAS-APP-001 — the isolated test workspace for the new Meta app.
 *
 * One new tenant and one new login, created by a platform admin with an address the Owner supplies; no password is chosen or shown
 * (the owner receives a reset link). Until Meta's approval is recorded it is the only tenant where the SaaS app may connect, and it
 * connects through the SaaS app only. Nothing in any other tenant — connection, token, account, app profile — is read or written.
 */
final class MetaSaasTestWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/settings/integrations/meta-saas';

    private const EMAIL = 'meta-test-owner@example.test';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Notification::fake();
        $this->admin = User::create(['name' => 'P', 'email' => 'p@workspace.test', 'password' => 'secret123']);
        $this->admin->forceFill(['is_platform_admin' => true])->save();
        config()->set('ad_platforms.platforms.meta.client_id', 'live-app-111111');
        config()->set('ad_platforms.platforms.meta.client_secret', 'live-secret-AAAAAAAAAAAA');
    }

    private function create(): Tenant
    {
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/test-workspace', ['email' => self::EMAIL])->assertCreated()
            ->assertJsonPath('data.test_workspace.exists', true)
            ->assertJsonPath('data.test_workspace.active', true)
            ->assertJsonPath('data.test_workspace.login_email', self::EMAIL);

        return Tenant::query()->where('slug', MetaSaasTestWorkspace::SLUG)->firstOrFail();
    }

    private function configureAndPass(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson(self::BASE, ['client_id' => 'saas-app-777', 'client_secret' => 'saas-secret-CCCC', 'config_id' => 'cfg-888'])->assertOk();
        app(MetaSaasCredentials::class)->forgetCache();
        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->admin->id, 'status' => 'succeeded',
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
    }

    private function owner(): User
    {
        return User::query()->where('email', self::EMAIL)->firstOrFail();
    }

    public function test_the_workspace_is_new_owned_by_a_new_login_and_no_password_is_chosen_or_shown(): void
    {
        $tenant = $this->create();

        $owner = $this->owner();
        $this->assertTrue($owner->memberships()->where('tenant_id', $tenant->id)->exists());
        $this->assertSame(1, $owner->memberships()->count(), 'the test login belongs to the test workspace only');
        $this->assertFalse(Hash::check('', (string) $owner->password));
        $this->assertTrue(MetaSaasTestWorkspace::isTestTenant((string) $tenant->id));
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'nothing is copied in');
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.integration.meta_saas.test_workspace_created']);

        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/test-workspace', ['email' => 'second@example.test'])
            ->assertStatus(409)->assertJsonPath('errors.email.0', 'test_workspace_exists');
    }

    public function test_an_existing_account_cannot_become_the_test_login(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson(self::BASE.'/test-workspace', ['email' => $this->admin->email])
            ->assertStatus(409)->assertJsonPath('errors.email.0', 'email_already_registered');
        $this->assertNull(Tenant::query()->where('slug', MetaSaasTestWorkspace::SLUG)->first());
    }

    public function test_only_a_platform_admin_creates_it(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@workspace.test', 'password' => 'secret123']);
        $this->actingAs($user, 'sanctum')->postJson(self::BASE.'/test-workspace', ['email' => self::EMAIL])->assertForbidden();
        $this->assertNull(Tenant::query()->where('slug', MetaSaasTestWorkspace::SLUG)->first());
    }

    public function test_without_saas_credentials_the_workspace_fails_safely_and_never_falls_back_to_live(): void
    {
        $tenant = $this->create();
        $as = $this->actingAs($this->owner(), 'sanctum');

        $as->getJson('/api/v1/integrations/meta/apps')->assertOk()
            ->assertJsonPath('data.apps.0.available', false)
            ->assertJsonPath('data.apps.1.available', false)
            ->assertJsonPath('data.apps.1.reason', 'not_configured');

        // Asking for Live, or for nothing, still means the SaaS app here — and it is not configured: refused, no URL, no state.
        foreach ([[], ['app_profile' => 'live'], ['app_profile' => 'saas']] as $body) {
            $res = $as->postJson('/api/v1/integrations/meta/oauth/start', $body)->assertStatus(422);
            $this->assertStringNotContainsString('live-app-111111', $res->getContent());
            $this->assertNull($res->json('data.authorization_url'));
        }
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    public function test_the_workspace_connects_through_the_saas_app_only_and_its_journey_is_read_from_rows(): void
    {
        $tenant = $this->create();
        $this->configureAndPass();
        $as = $this->actingAs($this->owner(), 'sanctum');

        $url = (string) $as->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'live'])->assertOk()
            ->assertJsonPath('data.app_profile', 'saas')->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('saas-app-777', $q['client_id'], 'the dialog names the new app, never Live');

        Http::fake(fn ($r) => str_contains($r->url(), 'oauth/access_token')
            ? Http::response(['access_token' => 'SAAS-TOKEN', 'expires_in' => 5184000])
            : (str_contains($r->url(), 'me/adaccounts')
                ? Http::response(['data' => [['id' => 'act_1', 'account_id' => '1', 'name' => 'Test account', 'currency' => 'SAR', 'account_status' => 1]]])
                : Http::response(['data' => []])));
        $this->get('/api/v1/oauth/ads/meta/callback?'.http_build_query(['state' => $q['state'], 'code' => 'c']))->assertRedirect();

        $connection = ProviderConnection::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('saas', $connection->app_profile);
        $this->assertSame('saas-app-777', $connection->issuing_app_id, 'the token is recorded as the new app\'s, never Live\'s');

        $steps = $this->actingAs($this->admin, 'sanctum')->getJson(self::BASE)->json('data.test_workspace.steps');
        $this->assertTrue($steps['oauth']);
        $this->assertSame(ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->exists(), $steps['discovery']);
        $this->assertFalse($steps['sync'], 'nothing synced yet, and the journey does not say otherwise');
        $this->assertFalse($steps['disconnect']);
    }

    public function test_a_customer_tenant_cannot_use_the_saas_app_before_approval_and_its_connections_stay_untouched(): void
    {
        $this->create();
        $this->configureAndPass();
        $customer = Tenant::create(['name' => 'Customer', 'slug' => 'customer-'.uniqid(), 'status' => 'active']);
        $live = app(TokenVault::class)->open(tenantId: (string) $customer->id, provider: 'meta', tokens: new OAuthTokens('CUSTOMER-LIVE', null, Carbon::now()->addDays(50)), connectionName: 'Meta');
        $before = $live->fresh()->toArray();

        $this->assertSame('test_workspace_only', MetaAppRouting::saasEligibilityFor((string) $customer->id)['reason']);
        $this->assertSame($before, $live->fresh()->toArray());
        $this->assertSame(MetaCredentialProfile::Live, MetaCredentialProfile::ofConnection($live->fresh()->app_profile));

        // Closing the workspace closes the app to it as well; nothing is deleted.
        $this->actingAs($this->admin, 'sanctum')->deleteJson(self::BASE.'/test-workspace')->assertOk()->assertJsonPath('data.test_workspace.active', false);
        $this->assertSame('test_workspace_only', MetaAppRouting::saasEligibilityFor((string) Tenant::query()->where('slug', MetaSaasTestWorkspace::SLUG)->value('id'))['reason']);
    }
}
