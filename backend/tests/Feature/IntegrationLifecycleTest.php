<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
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
use Tests\TestCase;

/**
 * INTEGRATION-LIFECYCLE-001 — Google Analytics connections end the same way store and ad connections do, through one service.
 *
 * Ending a connection: nothing reads through it (every project binding off), the secret is erased, the status says so — and every
 * property, figure and project link stays, listed as history with «Reconnect» beside it. Another property of the same agency, or of
 * another tenant, is never touched by it.
 */
final class IntegrationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private User $viewer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
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
        foreach (PlatformCredentials::for('ga4')->requires() as $key) {
            config()->set("measurement_platforms.platforms.ga4.{$key}", "test-{$key}");
        }
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function member(string $email, Role $role): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($role);

        return $u;
    }

    /** @return array{0: ProviderConnection, 1: ExternalAccount, 2: ProjectIntegrationBinding} */
    private function ga4(?Tenant $tenant = null, string $property = '111'): array
    {
        $tenant ??= $this->tenant;
        app(TenantContext::class)->setTenantId($tenant->id);
        $connection = app(TokenVault::class)->open(tenantId: $tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA '.uniqid());
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'ga4',
            'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'external_id' => $property, 'name' => 'Acme '.$property, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true, 'sync_enabled' => true,
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return [$connection, $account, $binding];
    }

    private function row(): array
    {
        return collect($this->actingAs($this->operator)->getJson('/api/v1/measurement/properties')->assertOk()->json('data'))->firstWhere('key', 'ga4');
    }

    public function test_the_card_names_the_connection_and_each_propertys_binding(): void
    {
        [$connection, , $binding] = $this->ga4();
        $row = $this->row();

        $this->assertSame($connection->id, $row['connection_id']);
        $this->assertSame('connected', $row['connection_status']);
        $this->assertSame($binding->id, $row['properties'][0]['binding_id']);
        $this->assertFalse($row['properties'][0]['disconnected']);
    }

    public function test_disconnecting_stops_reading_erases_the_token_and_keeps_the_property_the_figures_and_the_project_link(): void
    {
        [$connection, $account, $binding] = $this->ga4();
        MeasurementDailyMetric::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'property_id' => '111', 'metric_date' => '2026-10-07', 'metric_key' => 'sessions', 'value' => 4100, 'timezone' => 'Asia/Riyadh']);

        $this->actingAs($this->operator)->postJson("/api/v1/measurement/connections/{$connection->id}/disconnect")->assertOk()
            ->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.disabled_bindings', 1)->assertJsonPath('data.history_kept', true);

        $this->assertSame('revoked', $connection->fresh()->status);
        $this->assertSame('', IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id)->revealPayload());
        $this->assertFalse((bool) $binding->fresh()->is_active);
        $this->assertSame($this->project->id, $binding->fresh()->project_id, 'the link to the project is kept');
        $this->assertNotNull(ExternalAccount::withoutGlobalScopes()->find($account->id));
        $this->assertSame(1, MeasurementDailyMetric::withoutGlobalScopes()->where('external_account_id', $account->id)->count(), 'the figures are kept');

        $row = $this->row();
        $this->assertSame('disconnected', $row['state']);
        $this->assertSame('revoked', $row['connection_status']);
        $this->assertTrue($row['properties'][0]['disconnected']);
    }

    public function test_only_someone_who_may_disconnect_can_and_another_tenants_or_an_ad_connection_is_unreachable(): void
    {
        [$connection] = $this->ga4();
        $this->actingAs($this->viewer)->postJson("/api/v1/measurement/connections/{$connection->id}/disconnect")->assertForbidden();
        $this->assertSame('connected', $connection->fresh()->status);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        [$theirs] = $this->ga4($other, '999');
        $this->actingAs($this->operator)->postJson("/api/v1/measurement/connections/{$theirs->id}/disconnect")->assertNotFound();
        $this->assertSame('connected', ProviderConnection::withoutGlobalScopes()->find($theirs->id)->status);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $ads = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected']);
        $this->actingAs($this->operator)->postJson("/api/v1/measurement/connections/{$ads->id}/disconnect")->assertNotFound();
        $this->assertSame('connected', $ads->fresh()->status);
    }

    public function test_the_property_sync_history_is_newest_first_and_never_another_tenants(): void
    {
        [$connection, $account] = $this->ga4();
        foreach ([['failed', 'old'], ['success', null]] as $i => [$status, $err]) {
            IntegrationSyncRun::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'type' => 'measurement', 'status' => $status, 'records' => $i * 5, 'error' => $err, 'started_at' => Carbon::now()->subHours(5 - $i)]);
        }
        $r = $this->actingAs($this->operator)->getJson("/api/v1/measurement/properties/{$account->id}/runs")->assertOk();
        $this->assertSame(['success', 'failed'], array_column($r->json('data'), 'status'));

        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        [, $theirs] = $this->ga4($other, '999');
        $this->actingAs($this->operator)->getJson("/api/v1/measurement/properties/{$theirs->id}/runs")->assertNotFound();
    }

    public function test_the_generic_revoke_used_by_advertising_connections_erases_the_secret_too_and_keeps_history(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('secret-token');
        $credential->save();
        $ads = ProviderConnection::create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $ads->id, 'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Ads', 'status' => 'active']);
        $binding = ProjectIntegrationBinding::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true, 'sync_enabled' => true]);

        $this->actingAs($this->viewer)->postJson("/api/v1/connections/{$ads->id}/revoke")->assertForbidden();
        $this->assertSame('secret-token', IntegrationCredential::withoutGlobalScopes()->find($credential->id)->revealPayload());

        $this->actingAs($this->operator)->postJson("/api/v1/connections/{$ads->id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame('', IntegrationCredential::withoutGlobalScopes()->find($credential->id)->revealPayload());
        $this->assertFalse((bool) $binding->fresh()->is_active);
        $this->assertNotNull(ExternalAccount::withoutGlobalScopes()->find($account->id));
    }
}
