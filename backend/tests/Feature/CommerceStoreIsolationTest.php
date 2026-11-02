<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Install\SallaOnboarding;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\PendingStoreInstall;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SEC-3 — a store feeds a project only when somebody chose that project, and the choice is authorised.
 *
 * Tenant -> client -> project -> store. These hold the four ways a store used to land somewhere nobody
 * chose it (auto-assign with no client named, Salla's oldest-client guess) or somebody unauthorised
 * chose it (PATCH, install claim).
 */
final class CommerceStoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        Queue::fake();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->owner = $this->userWith('owner@ag.test', Permission::pluck('key')->all(), Portal::Agency);

        foreach (['salla', 'zid'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
    }

    // ── autoAssign: an unattributed store is not filed into "the only project" ─────────────────

    public function test_an_unattributed_store_is_not_auto_assigned_when_the_tenant_has_several_clients(): void
    {
        $a = $this->client('Client A');
        $this->client('Client B'); // B has no project yet; the store is B's
        $this->project('A only', $a);
        $this->fakeSallaStoreInfo();

        $this->connectThroughCallback('salla');

        $store = ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->firstOrFail();
        $this->assertNull($store->client_workspace_id);
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->count(), 'nobody chose client A\'s project for client B\'s store');
        Queue::assertNotPushed(SyncStoreJob::class);
    }

    public function test_a_store_named_for_a_client_with_one_project_is_still_auto_assigned_even_when_the_tenant_has_other_clients(): void
    {
        $a = $this->client('Client A');
        $this->client('Client B');
        $only = $this->project('A only', $a);
        $this->fakeSallaStoreInfo();

        $start = $this->actingAs($this->owner)->postJson('/api/v1/integrations/commerce/salla/oauth/start', ['client_workspace_id' => $a->id])->assertOk();
        parse_str((string) parse_url((string) $start->json('data.authorization_url'), PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/commerce/salla/callback?code=c&state={$q['state']}")->assertRedirect();

        $store = ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->firstOrFail();
        $this->assertSame($only->id, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->value('project_id'));
    }

    // ── SallaOnboarding: never the oldest client ───────────────────────────────────────────────

    public function test_a_salla_install_by_an_agency_member_is_parked_for_the_claim_page_not_filed_under_the_oldest_client(): void
    {
        $oldest = $this->client('Oldest client');
        $this->client('Second client');
        $this->fakeSallaInstallIdentity('owner@ag.test');
        $projectsBefore = Project::withoutGlobalScopes()->count();
        app(TenantContext::class)->forget();

        $this->get('/api/v1/oauth/commerce/salla/callback?code=from-salla');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertSame($projectsBefore, Project::withoutGlobalScopes()->count(), 'no project was minted under a client nobody chose');
        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        $pending = PendingStoreInstall::query()->firstOrFail();
        $this->assertNull($pending->claimed_at, 'the install waits for a person to place it');
        $this->assertNotNull($oldest->id);
    }

    public function test_a_self_serve_account_with_two_clients_is_also_asked_which(): void
    {
        $tenant = Tenant::create(['name' => 'Self', 'slug' => 'self-'.uniqid(), 'status' => 'active', 'account_type' => 'self_serve_company']);
        $this->holdingTenant((string) $tenant->id);
        $user = $this->userWith('merchant@store.test', Permission::pluck('key')->all(), Portal::App, $tenant);
        $this->client('One', $tenant);
        $this->client('Two', $tenant);
        $this->fakeSallaInstallIdentity('merchant@store.test');
        app(TenantContext::class)->forget();

        $this->get('/api/v1/oauth/commerce/salla/callback?code=from-salla');

        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $this->assertSame(0, Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull($user->id);
        $this->assertNull(PendingStoreInstall::query()->firstOrFail()->claimed_at);
    }

    public function test_a_salla_install_by_a_member_of_an_agency_with_no_clients_creates_one(): void
    {
        $this->fakeSallaInstallIdentity('owner@ag.test');
        app(TenantContext::class)->forget();

        $this->get('/api/v1/oauth/commerce/salla/callback?code=from-salla');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->assertSame(1, ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, Project::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    // ── PATCH /commerce/stores/{store}: the project choice is authorised ──────────────────────

    public function test_a_user_with_no_reach_into_the_project_cannot_assign_a_store_to_it(): void
    {
        $a = $this->client('A');
        $project = $this->project('P', $a);
        $store = $this->store('salla', 's-1');
        $limited = $this->userWith('lim@ag.test', ['integrations.connect', 'projects.view'], Portal::Agency);

        $this->actingAs($limited)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $project->id])->assertForbidden();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->count());
    }

    public function test_a_client_scoped_user_cannot_assign_or_detach_across_their_ceiling(): void
    {
        $a = $this->client('A');
        $b = $this->client('B');
        $pa = $this->project('PA', $a);
        $pb = $this->project('PB', $b);
        $store = $this->store('salla', 's-1'); // tenant-level: mayAssign passes for any project
        $this->actingAs($this->owner)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $pb->id])->assertOk();
        $scoped = $this->userWith('scoped@ag.test', ['integrations.connect', 'projects.view', 'projects.view.all', 'settings.manage'], Portal::Agency, null, [$a->id]);

        // Into a project outside the ceiling.
        $this->actingAs($scoped)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => $pa->id])->assertForbidden();
        // Away from a project outside the ceiling.
        $this->actingAs($scoped)->patchJson("/api/v1/commerce/stores/{$store->id}", ['project_id' => null])->assertForbidden();

        $active = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->pluck('project_id')->all();
        $this->assertSame([$pb->id], $active);
    }

    // ── Install claim: the workspace is verified ───────────────────────────────────────────────

    public function test_a_claim_naming_another_tenants_client_workspace_is_refused_and_leaves_the_install_pending(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => 'active']);
        $theirs = $this->client('Theirs', $other);
        $this->fakeSallaStoreInfo();
        $claim = $this->pendingInstall();

        $this->actingAs($this->owner)->postJson("/api/v1/commerce/store-installs/{$claim}/claim", ['client_workspace_id' => $theirs->id])
            ->assertStatus(422);

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->count());
        $this->assertNull(PendingStoreInstall::query()->firstOrFail()->claimed_at);
    }

    public function test_a_claim_naming_a_client_outside_the_callers_ceiling_is_refused(): void
    {
        $a = $this->client('A');
        $b = $this->client('B');
        $this->project('PB', $b);
        $this->fakeSallaStoreInfo();
        $claim = $this->pendingInstall();
        $scoped = $this->userWith('scoped@ag.test', ['integrations.connect', 'projects.view'], Portal::Agency, null, [$a->id]);

        $this->actingAs($scoped)->postJson("/api/v1/commerce/store-installs/{$claim}/claim", ['client_workspace_id' => $b->id])
            ->assertForbidden();

        $this->assertSame(0, ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->count());
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());
    }

    public function test_a_claim_naming_a_client_of_the_tenant_still_works(): void
    {
        $a = $this->client('A');
        $only = $this->project('PA', $a);
        $this->fakeSallaStoreInfo();
        $claim = $this->pendingInstall();

        $this->actingAs($this->owner)->postJson("/api/v1/commerce/store-installs/{$claim}/claim", ['client_workspace_id' => $a->id])->assertOk();

        $store = ExternalAccount::withoutGlobalScopes()->where('account_type', 'store')->firstOrFail();
        $this->assertSame($a->id, $store->client_workspace_id);
        $this->assertSame($only->id, ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->where('is_active', true)->value('project_id'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $clientScope
     */
    private function userWith(string $email, array $permissions, Portal $portal, ?Tenant $tenant = null, array $clientScope = []): User
    {
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: $portal, role: 'member', clientScopeIds: $clientScope));

        return $user;
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $client): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $client->tenant_id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function store(string $provider, string $externalId): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: $provider.'-'.$externalId,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => null,
            'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'store', 'external_id' => $externalId, 'name' => 'Store', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function fakeSallaStoreInfo(): void
    {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 'store-9', 'name' => 'Demo', 'domain' => 'demo.salla.sa', 'currency' => 'SAR']]),
        ]);
    }

    private function fakeSallaInstallIdentity(string $email): void
    {
        Http::fake([
            'accounts.salla.sa/oauth2/token' => Http::response(['access_token' => 'AT-install', 'refresh_token' => 'RT-install', 'expires_in' => 1209600]),
            'api.salla.dev/*/store/info' => Http::response(['data' => ['id' => 793298857, 'name' => 'Fashion', 'domain' => 'https://fashion.salla.sa/x', 'currency' => 'SAR']]),
            'api.salla.dev/*/oauth2/user/info' => Http::response(['data' => ['id' => 5, 'name' => 'Salma', 'email' => $email, 'mobile' => '+966500000000', 'role' => 'owner']]),
        ]);
    }

    private function connectThroughCallback(string $provider): void
    {
        $start = $this->actingAs($this->owner)->postJson("/api/v1/integrations/commerce/{$provider}/oauth/start")->assertOk();
        parse_str((string) parse_url((string) $start->json('data.authorization_url'), PHP_URL_QUERY), $q);
        $this->get("/api/v1/oauth/commerce/{$provider}/callback?code=real-code&state={$q['state']}")->assertRedirect();
    }

    private function pendingInstall(): string
    {
        $code = 'claim-code-'.uniqid();
        PendingStoreInstall::query()->create([
            'provider' => 'salla', 'merchant_id' => 'store-9', 'store_name' => 'Demo', 'store_domain' => 'demo.salla.sa', 'store_currency' => 'SAR',
            'tokens' => ['access_token' => 'AT', 'refresh_token' => 'RT'],
            'claim_code_hash' => hash('sha256', $code), 'expires_at' => Carbon::now()->addDays(3),
        ]);

        return $code;
    }
}
