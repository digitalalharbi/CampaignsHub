<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\MetaCandidate\MetaCandidateConnection;
use App\Domains\Integrations\MetaCandidate\MetaCandidateCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasCredentials;
use App\Domains\Integrations\MetaCandidate\MetaSaasTestWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * META-SAAS-APP-001 — a THIRD Meta app, independent of Live and of the Candidate.
 *
 *  - its credentials live in their own row and never borrow or overwrite a Live or Candidate value;
 *  - a customer connection it issues is a SEPARATE connection, bound to it for life: the Live connection's tokens are untouched, and the
 *    SaaS token is refreshed and used with the SaaS app's own App ID and secret;
 *  - new connections use it only after an admin chose so, which needs a passed test with the current credentials and the Owner's
 *    record that Meta approved the app;
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

    /** A passed SaaS test round trip with the credentials configured now — what makes the SaaS app eligible for customer connections. */
    private function passSaasTest(): void
    {
        $run = new MetaCandidateConnection;
        $run->forceFill([
            'profile' => 'saas', 'started_by' => $this->platformOwner->id, 'status' => 'succeeded',
            'credential_fingerprint' => app(MetaSaasCredentials::class)->fingerprint(), 'started_at' => now(),
        ])->save();
    }

    /** Before Meta's approval only the designated test workspace may connect through the SaaS app; this tenant plays that role. */
    private function asTestWorkspace(): void
    {
        $this->tenant->forceFill(['settings' => [...(array) ($this->tenant->settings ?? []), MetaSaasTestWorkspace::SETTINGS_KEY => ['active' => true]]])->save();
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

        // Named by an operator, the SaaS app is still refused until its test passed with these credentials.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'saas'])
            ->assertStatus(422)->assertJsonPath('meta.status', 'not_eligible');
        $this->getJson('/api/v1/integrations/meta/apps')->assertOk()->assertJsonPath('data.apps.1.available', false)->assertJsonPath('data.default', 'live');
        $this->passSaasTest();
        // A passed test is not enough in a customer tenant before Meta's approval: only the test workspace may use the app.
        $this->getJson('/api/v1/integrations/meta/apps')->assertOk()->assertJsonPath('data.apps.1.available', false)->assertJsonPath('data.apps.1.reason', 'test_workspace_only');
        $this->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'saas'])->assertStatus(422)->assertJsonPath('errors.app_profile.0', 'test_workspace_only');
        $this->asTestWorkspace();
        $this->getJson('/api/v1/integrations/meta/apps')->assertOk()->assertJsonPath('data.apps.1.available', true)
            ->assertJsonPath('data.apps.0.available', false)->assertJsonPath('data.default', 'saas');

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

        // A passed test proves the app for its own app roles only; customers wait for the Owner's record that Meta approved it.
        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/routing', ['default' => 'saas', 'confirm' => true])->assertStatus(409)
            ->assertJsonPath('errors.routing.0', 'app_review_not_confirmed');
        $admin->putJson('/api/v1/admin/settings/integrations/meta-saas/review', ['business_verified' => true, 'advanced_access_granted' => true])->assertOk();

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

    /** Nothing a move touches can lose history: no figure, no binding, no other customer's row — not even through the discovery twin. */
    public function test_a_move_deletes_no_metric_no_binding_and_no_other_customers_row(): void
    {
        $this->configureSaas();
        $live = $this->liveConnection();
        $saas = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('S', null, Carbon::now()->addDays(50)),
            connectionName: 'SaaS', appProfile: MetaCredentialProfile::Saas,
        );
        $make = fn (ProviderConnection $c, string $externalId, ?string $tenantId = null): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId ?? $this->tenant->id, 'provider_connection_id' => $c->id, 'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'currency' => 'USD', 'status' => 'active',
        ]);
        $account = $make($live, 'act_1');
        $twin = $make($saas, 'act_1');

        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'NASEL', 'status' => 'active']);
        // The moved account's history: an active binding, an earlier deactivated one, and figures.
        foreach ([[true, 'reporting'], [false, 'tracking']] as [$active, $purpose]) {
            ProjectIntegrationBinding::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
                'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => $purpose, 'is_active' => $active, 'is_primary' => $active,
            ]);
        }
        $metric = fn (ExternalAccount $a, float $v) => DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $a->tenant_id, 'project_id' => $project->id, 'external_account_id' => $a->id,
            'external_campaign_id' => (string) Str::uuid(), 'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-10-20', 'value' => $v,
        ]);
        $metric($account, 100.0);
        // A twin that holds figures although it was never bound and never synced (seeded or legacy history) — the worst case for a cleanup.
        $metric($twin, 7.0);

        // Another customer's account with the same provider id, on another tenant: untouchable.
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $otherConnection = app(TokenVault::class)->open(tenantId: (string) $otherTenant->id, provider: 'meta', tokens: new OAuthTokens('O', null, Carbon::now()->addDays(50)), connectionName: 'Other');
        $foreign = $make($otherConnection, 'act_1', (string) $otherTenant->id);

        $metricsBefore = DB::table('daily_metrics')->count();
        $bindingsBefore = DB::table('project_integration_bindings')->count();
        $accountsBefore = DB::table('external_accounts')->count();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/accounts/{$account->id}/move-to-connection", ['connection_id' => $saas->id, 'confirm' => true])->assertOk();

        $this->assertSame($metricsBefore, DB::table('daily_metrics')->count(), 'no figure was deleted');
        $this->assertSame($bindingsBefore, DB::table('project_integration_bindings')->count(), 'no binding was deleted');
        $this->assertSame($accountsBefore, DB::table('external_accounts')->count(), 'no account row was deleted — the twin is retired, not removed');
        $this->assertSame(2, DB::table('project_integration_bindings')->where('external_account_id', $account->id)->count());
        $this->assertSame('superseded', $twin->refresh()->status);
        $this->assertSame(1.0, (float) DB::table('daily_metrics')->where('external_account_id', $twin->id)->count());
        $this->assertSame((string) $otherConnection->id, (string) $foreign->refresh()->provider_connection_id);
        $this->assertSame('act_1', $foreign->external_id);

        // The retired twin never counts twice and never reappears: its figures are invisible to every reader, the inventory does not list
        // it, and a rediscovery of act_1 through the SaaS connection lands on the MOVED row, not on the twin.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $visible = DailyMetric::query()->where('project_id', $project->id)
            ->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))->sum('value');
        $this->assertEquals(100.0, (float) $visible, 'only the moved account\'s 100 counts; the twin\'s 7 is not added');
        $listed = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/accounts')->assertOk()->json('data.accounts'))->pluck('id')->all();
        $this->assertContains((string) $account->id, $listed);
        $this->assertNotContains((string) $twin->id, $listed, 'a superseded twin is not listed as an account');
        $rediscovered = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $saas->id)->where('external_id', 'act_1')->where('account_type', 'ad_account')->first();
        $this->assertSame((string) $account->id, (string) $rediscovered?->id, 'a rediscovery updates the moved row, never the twin');

        // The retired twin is never synced again — not even if somebody later points an active binding at it.
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
            'external_account_id' => $twin->id, 'provider' => 'meta', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => false,
        ]);
        $this->assertFalse(app(AccountAssignment::class)->isActivelyAssigned($twin->refresh()), 'a superseded twin is never authorised to sync');
        Http::fake();
        (new SyncAccountMetricsJob((string) $twin->id, '2026-10-01', '2026-10-27'))
            ->handle(app(AccountMetricsSyncer::class), app(TenantContext::class), app(AccountAssignment::class));
        $this->assertSame(0, MetricSyncRun::withoutGlobalScopes()->where('external_account_id', $twin->id)->count(), 'the job did not even open a run');
        Http::assertNothingSent();

        // Another tenant's account can never be moved from here.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/accounts/{$foreign->id}/move-to-connection", ['connection_id' => $saas->id, 'confirm' => true])->assertNotFound();
    }

    /**
     * The whole SaaS journey, end to end with Meta faked: login → consent → callback → token → discovery → binding → sync → the
     * figures a report reads → reconnect → disconnect — every Meta call with the SaaS app's token, the Live connection untouched
     * throughout, and another customer seeing none of it.
     */
    public function test_the_saas_connection_journey_from_login_to_disconnect_stays_on_its_own_app_and_tenant(): void
    {
        $live = $this->liveConnection();
        $liveToken = app(TokenVault::class)->stored($live)->accessToken;
        $this->configureSaas();
        $this->passSaasTest();
        $this->asTestWorkspace();
        $as = $this->actingAs($this->owner, 'sanctum');

        // One fake for the whole journey — a second Http::fake() only appends stubs and the first match keeps winning.
        $issued = 'SAAS-TOKEN-1';
        Http::fake(function (HttpRequest $r) use (&$issued) {
            return match (true) {
                str_contains($r->url(), 'oauth/access_token') => Http::response(['access_token' => $issued, 'expires_in' => 5184000]),
                str_contains($r->url(), 'me/adaccounts') => Http::response(['data' => [
                    ['id' => 'act_900', 'account_id' => '900', 'name' => 'SaaS test account', 'currency' => 'SAR', 'account_status' => 1],
                ]]),
                default => Http::response(['data' => []]),
            };
        });
        $login = function (string $token) use ($as, &$issued): void {
            $issued = $token;
            $url = (string) $as->postJson('/api/v1/integrations/meta/oauth/start', ['app_profile' => 'saas'])->assertOk()->json('data.authorization_url');
            $this->get('/api/v1/oauth/ads/meta/callback?'.http_build_query(['state' => $this->query($url)['state'], 'code' => 'c-'.$token]))->assertRedirect();
        };

        // Login → consent → callback → token → discovery.
        $login('SAAS-TOKEN-1');
        $saas = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->where('app_profile', 'saas')->sole();
        $account = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $saas->id)->where('external_id', 'like', '%900')->sole();
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $live->id)->count(), 'nothing was discovered onto Live');

        // Binding → sync: every Graph call carries the SaaS token (and, when signed, the SaaS app's proof), never the Live one.
        $workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => 'SaaS client', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => true,
        ]);
        $before = count(Http::recorded());
        app(TenantContext::class)->setTenantId($this->tenant->id);
        (new SyncAccountMetricsJob((string) $account->id, '2026-10-01', '2026-10-27'))
            ->handle(app(AccountMetricsSyncer::class), app(TenantContext::class), app(AccountAssignment::class));
        $this->assertSame(1, MetricSyncRun::withoutGlobalScopes()->where('external_account_id', $account->id)->count(), 'the sync ran');
        $graph = collect(Http::recorded())->slice($before)->map(fn ($pair) => $pair[0])->filter(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertNotEmpty($graph, 'the sync called Meta');
        foreach ($graph as $r) {
            $sent = urldecode($r->url().' '.$r->body().' '.json_encode($r->headers()));
            $this->assertStringNotContainsString($liveToken, $sent, 'a SaaS sync used the Live token');
            $this->assertStringNotContainsString(self::LIVE_SECRET, $sent);
            $this->assertStringContainsString('SAAS-TOKEN-1', $sent, 'a SaaS sync call did not carry the SaaS token');
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            if (isset($q['appsecret_proof'])) {
                $this->assertSame(hash_hmac('sha256', 'SAAS-TOKEN-1', self::SAAS_SECRET), $q['appsecret_proof']);
            }
        }

        // The figures a report reads: the bound SaaS account's, and only through the project's visibility rule.
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => (string) Str::uuid(), 'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-10-20', 'value' => 250.0,
        ]);
        $visible = DailyMetric::query()->where('project_id', $project->id)->tap(fn ($q) => BoundAccountVisibility::apply($q, 'daily_metrics'))->sum('value');
        $this->assertEquals(250.0, (float) $visible);

        // Reconnect: the same SaaS connection takes the new token; no third connection; Live untouched.
        $login('SAAS-TOKEN-2');
        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->count(), 'a reconnect opened no new connection');
        $this->assertSame('SAAS-TOKEN-2', app(TokenVault::class)->stored($saas->refresh())->accessToken);
        $this->assertSame($liveToken, app(TokenVault::class)->stored($live->refresh())->accessToken);
        $this->assertSame((string) $saas->id, (string) $account->refresh()->provider_connection_id, 'the account stayed on its row and connection');

        // Another customer sees none of it and cannot act on it.
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $otherRole = Role::create(['tenant_id' => $otherTenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $otherRole->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's@other.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $otherTenant);
        $stranger->assignRole($otherRole);
        app(TenantContext::class)->setTenantId($otherTenant->id);
        $theirs = $this->actingAs($stranger, 'sanctum');
        $this->assertNotContains((string) $account->id, collect($theirs->getJson('/api/v1/accounts')->assertOk()->json('data.accounts'))->pluck('id')->all());
        $this->assertNotContains((string) $saas->id, collect($theirs->getJson('/api/v1/connections')->assertOk()->json('data'))->pluck('id')->all());
        $theirs->postJson("/api/v1/connections/{$saas->id}/revoke")->assertNotFound();
        $this->assertSame('connected', $saas->refresh()->status);

        // Disconnect the SaaS connection: Live stays connected with its token; no account row and no figure is lost.
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $metrics = DB::table('daily_metrics')->count();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/connections/{$saas->id}/revoke")->assertOk();
        $this->assertNotSame('connected', $saas->refresh()->status);
        $this->assertSame('connected', $live->refresh()->status);
        $this->assertSame($liveToken, app(TokenVault::class)->stored($live)->accessToken);
        $this->assertNotNull(ExternalAccount::withoutGlobalScopes()->find($account->id));
        $this->assertSame($metrics, DB::table('daily_metrics')->count());
    }
}
