<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * STORE-LIFECYCLE-001 — a connected Salla/Zid store can be disconnected, reconnected and read back as history.
 *
 * The Owner: «the Salla connection cannot be changed or disconnected». The store card offered Sync and a project picker and nothing
 * else once a store was connected. Disconnect stops reading and erases the token but never deletes what was read; the disconnected
 * store stays listed (marked) with its history, and the connect button is offered again.
 */
final class StoreLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = $this->member('o@ag.test', $role);
        $view = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $view->givePermissionTo('integrations.view');
        $this->viewer = $this->member('v@ag.test', $view);
        foreach (['salla', 'zid'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
    }

    private function member(string $email, Role $role): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($role);

        return $u;
    }

    private function store(?Tenant $tenant = null): ExternalAccount
    {
        $tenant ??= $this->tenant;
        app(TenantContext::class)->setTenantId($tenant->id);
        $connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: 'salla-'.uniqid());
        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'salla',
            'account_type' => 'store', 'external_id' => 's-'.uniqid(), 'name' => 'Demo store', 'currency' => 'SAR', 'status' => 'active',
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return $store;
    }

    private function bound(ExternalAccount $store): Project
    {
        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'w-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'project_id' => $project->id, 'external_account_id' => $store->id,
            'provider' => 'salla', 'purpose' => 'ecommerce', 'is_active' => true, 'sync_enabled' => true,
        ]);

        return $project;
    }

    private function salla(): array
    {
        $rows = $this->actingAs($this->operator)->getJson('/api/v1/commerce/stores')->assertOk()->json('data');

        return collect($rows)->firstWhere('key', 'salla');
    }

    public function test_the_card_names_the_connection_so_it_can_be_acted_on(): void
    {
        $store = $this->store();
        $row = $this->salla();

        $this->assertSame($store->provider_connection_id, $row['connection_id']);
        $this->assertSame('connected', $row['connection_status']);
        $this->assertFalse($row['stores'][0]['disconnected']);
    }

    public function test_disconnecting_stops_syncing_erases_the_token_and_keeps_every_record(): void
    {
        $store = $this->store();
        $project = $this->bound($store);
        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($store->provider_connection_id);
        $this->assertNotSame('', IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id)->revealPayload());

        $r = $this->actingAs($this->operator)->postJson("/api/v1/commerce/connections/{$connection->id}/disconnect")->assertOk();
        $r->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.disabled_bindings', 1)->assertJsonPath('data.history_kept', true);

        $this->assertSame('revoked', $connection->fresh()->status);
        $this->assertSame('', IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id)->revealPayload(), 'the token is erased');
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $store->id)->firstOrFail();
        $this->assertFalse((bool) $binding->is_active, 'nothing syncs');
        $this->assertSame($project->id, $binding->project_id, 'the project link is kept, only switched off');
        $this->assertNotNull(ExternalAccount::withoutGlobalScopes()->find($store->id), 'the store row and its history stay');
        $this->assertNotNull(AuditLog::withoutGlobalScopes()->where('action', 'commerce.connection.disconnected')->first());
    }

    public function test_a_disconnected_store_stays_listed_as_history_with_a_connect_offer(): void
    {
        $store = $this->store();
        $connection = ProviderConnection::withoutGlobalScopes()->findOrFail($store->provider_connection_id);
        $this->actingAs($this->operator)->postJson("/api/v1/commerce/connections/{$connection->id}/disconnect")->assertOk();

        $row = $this->salla();
        $this->assertSame('disconnected', $row['state']);
        $this->assertSame('revoked', $row['connection_status']);
        $this->assertCount(1, $row['stores']);
        $this->assertTrue($row['stores'][0]['disconnected']);
        $this->assertSame($store->id, $row['stores'][0]['id']);
    }

    public function test_only_someone_who_may_disconnect_can_and_another_tenant_cannot_reach_the_connection(): void
    {
        $store = $this->store();
        $this->actingAs($this->viewer)->postJson("/api/v1/commerce/connections/{$store->provider_connection_id}/disconnect")->assertForbidden();
        $this->assertSame('connected', ProviderConnection::withoutGlobalScopes()->find($store->provider_connection_id)->status);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        $theirs = $this->store($other);
        $this->actingAs($this->operator)->postJson("/api/v1/commerce/connections/{$theirs->provider_connection_id}/disconnect")->assertNotFound();
        $this->assertSame('connected', ProviderConnection::withoutGlobalScopes()->find($theirs->provider_connection_id)->status);
    }

    public function test_an_advertising_connection_is_not_reachable_through_the_store_endpoint(): void
    {
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $ads = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected']);

        $this->actingAs($this->operator)->postJson("/api/v1/commerce/connections/{$ads->id}/disconnect")->assertNotFound();
        $this->assertSame('connected', $ads->fresh()->status);
    }

    public function test_the_sync_history_lists_recent_runs_newest_first_and_only_for_this_tenants_store(): void
    {
        $store = $this->store();
        foreach ([['failed', 'old'], ['success', null]] as $i => [$status, $err]) {
            IntegrationSyncRun::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $store->provider_connection_id, 'type' => 'commerce', 'status' => $status, 'records' => $i * 10, 'error' => $err, 'started_at' => Carbon::now()->subHours(5 - $i)]);
        }
        $r = $this->actingAs($this->operator)->getJson("/api/v1/commerce/stores/{$store->id}/runs")->assertOk();
        $this->assertSame(['success', 'failed'], array_column($r->json('data'), 'status'));

        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        $theirs = $this->store($other);
        $this->actingAs($this->operator)->getJson("/api/v1/commerce/stores/{$theirs->id}/runs")->assertNotFound();
    }
}
