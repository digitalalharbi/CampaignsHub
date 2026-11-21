<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INTEG-SOURCE-REMOVAL-001 — a source can be taken out of the hub, and nothing it stored goes with it.
 *
 * The Owner's report: a revoked or abandoned source stayed in the list for ever and nothing could remove it.
 */
final class SourceRemovalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create(['name' => 'Owner One', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);
    }

    public function test_remove_disables_bindings_clears_the_credential_and_keeps_every_stored_row(): void
    {
        $connection = $this->connection();
        $account = $this->account($connection, 'act_1');
        $other = $this->account($connection, 'act_2');
        $binding = $this->bind($this->project('P1'), $account);
        $second = $this->bind($this->project('P2'), $other);
        $this->syncRun($account);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/connections/{$connection->id}/remove")
            ->assertOk()
            ->assertJsonPath('data.removed', true)
            ->assertJsonPath('data.disabled_bindings', 2);

        $connection->refresh();
        $this->assertNotNull($connection->removed_at);
        $this->assertSame('revoked', $connection->status);
        $this->assertFalse((bool) $binding->fresh()->is_active);
        $this->assertFalse((bool) $second->fresh()->is_active);

        // The secret is gone from the credential row; the row itself stays.
        $credential = IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id);
        $this->assertNotNull($credential);
        $this->assertSame('{}', $credential->revealPayload());
        $this->assertSame('revoked', $credential->status);

        // History is untouched: accounts, bindings (disabled, not deleted), runs.
        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->count());
        $this->assertSame(2, ProjectIntegrationBinding::withoutGlobalScopes()->count());
        $this->assertSame(1, MetricSyncRun::withoutGlobalScopes()->count());

        $this->assertTrue(AuditLog::where('action', 'integration.connection.removed')->where('entity_id', (string) $connection->id)->exists());
    }

    public function test_a_removed_or_revoked_source_leaves_the_hub_and_returns_with_the_toggle(): void
    {
        $removed = $this->connection();
        $revoked = $this->connection();
        $live = $this->connection();
        $this->postRemove($removed)->assertOk();
        $revoked->forceFill(['status' => 'revoked'])->save();

        $this->assertSame([(string) $live->id], array_column($this->hub()['connections'], 'id'));

        $all = array_column($this->hub('?include_removed=1')['connections'], 'id');
        $this->assertEqualsCanonicalizing([(string) $removed->id, (string) $revoked->id, (string) $live->id], $all);
    }

    public function test_only_a_user_with_the_disconnect_permission_may_remove(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $role->givePermissionTo('integrations.view');
        $viewer->assignRole($role);

        $connection = $this->connection();

        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/connections/{$connection->id}/remove")->assertForbidden();
        $this->assertNull($connection->fresh()->removed_at);
    }

    public function test_another_tenants_connection_cannot_be_removed_or_even_seen(): void
    {
        $theirs = $this->connectionForOtherTenant();

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/connections/{$theirs->id}/remove")->assertNotFound();
        $this->assertNull(ProviderConnection::withoutGlobalScopes()->find($theirs->id)->removed_at);
        $this->assertNotContains((string) $theirs->id, array_column($this->hub('?include_removed=1')['connections'], 'id'));
    }

    public function test_cancelling_the_wizard_removes_only_an_unconfirmed_connection(): void
    {
        $fresh = $this->connection();
        $this->account($fresh, 'act_free');

        $working = $this->connection();
        $this->bind($this->project('P'), $this->account($working, 'act_used'));

        $this->postRemove($fresh, true)->assertOk()->assertJsonPath('data.removed', true);
        $this->postRemove($working, true)->assertOk()->assertJsonPath('data.removed', false);

        $this->assertNotNull($fresh->fresh()->removed_at);
        $this->assertNull($working->fresh()->removed_at);
        $this->assertSame('connected', $working->fresh()->status);
    }

    public function test_an_old_unfinished_connection_is_flagged_and_the_sweep_hides_only_the_unreferenced_one(): void
    {
        $abandoned = $this->connection();
        $this->account($abandoned, 'act_a');
        $referenced = $this->connection();
        $account = $this->account($referenced, 'act_b');
        $this->syncRun($account); // a run row references it, though nothing is selected
        $young = $this->connection();

        foreach ([$abandoned, $referenced] as $c) {
            $c->forceFill(['created_at' => Carbon::now()->subDays(3)])->save();
        }

        $rows = collect($this->hub()['connections'])->keyBy('id');
        $this->assertTrue($rows[(string) $abandoned->id]['abandoned']);
        $this->assertFalse($rows[(string) $young->id]['abandoned']);

        // Dry-run by default: nothing changes.
        $this->artisan('integrations:sweep-abandoned')->assertSuccessful();
        $this->assertNull($abandoned->fresh()->removed_at);

        // --apply is inert while the env flag is off.
        $this->artisan('integrations:sweep-abandoned --apply')->assertSuccessful();
        $this->assertNull($abandoned->fresh()->removed_at);

        config(['integrations.abandoned_sweep_enabled' => true]);
        $this->artisan('integrations:sweep-abandoned --apply')->assertSuccessful();

        $this->assertNotNull($abandoned->fresh()->removed_at);
        $this->assertNull($referenced->fresh()->removed_at, 'a connection with a metric run is never swept');
        $this->assertNull($young->fresh()->removed_at);
    }

    public function test_reconnecting_after_removal_restores_a_working_connection_and_does_not_count_while_removed(): void
    {
        config(['ad_platforms.platforms.snapchat.client_id' => 'id', 'ad_platforms.platforms.snapchat.client_secret' => 'secret']);

        $connection = $this->connection('snapchat');
        $account = $this->account($connection, 'act_s', 'snapchat');
        $before = app(SubscriptionService::class)->usage($this->tenant, 'connections');
        $this->postRemove($connection)->assertOk();
        $this->assertSame($before - 1, app(SubscriptionService::class)->usage($this->tenant, 'connections'));

        $reopened = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens(accessToken: 'fresh-token', refreshToken: 'r', expiresAt: Carbon::now()->addHour()),
            connectionName: 'Snapchat',
        );

        $this->assertSame($connection->id, $reopened->id, 'the same row is restored so accounts and history keep one identity');
        $reopened->refresh();
        $this->assertNull($reopened->removed_at);
        $this->assertSame('connected', $reopened->status);
        $this->assertSame('fresh-token', app(TokenVault::class)->stored($reopened)->accessToken);
        $this->assertSame($account->id, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $reopened->id)->value('id'));
        $this->assertSame($before, app(SubscriptionService::class)->usage($this->tenant, 'connections'));
    }

    private function postRemove(ProviderConnection $c, bool $unconfirmedOnly = false)
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/connections/{$c->id}/remove", $unconfirmedOnly ? ['unconfirmed_only' => true] : []);
    }

    /** @return array<string,mixed> */
    private function hub(string $query = ''): array
    {
        return $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/integrations/hub'.$query)->assertOk()->json('data');
    }

    private function connection(string $provider = 'meta'): ProviderConnection
    {
        return $this->makeConnection($this->tenant, $provider);
    }

    private function connectionForOtherTenant(): ProviderConnection
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);

        return $this->makeConnection($other, 'meta');
    }

    private function makeConnection(Tenant $tenant, string $provider): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->forceFill(['tenant_id' => $tenant->id]);
        $credential->setPayload(json_encode(['access_token' => 'secret-token']));
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => ucfirst($provider), 'scope' => 'tenant_shared', 'status' => 'connected', 'scopes' => ['ads_read'],
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId, string $provider = 'meta'): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'external_id' => $externalId, 'name' => 'Account '.$externalId, 'account_type' => 'ad_account', 'status' => 'active',
        ]);
    }

    private function project(string $name): Project
    {
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed',
        ]);

        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'external_account_id' => $account->id, 'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);
    }

    private function syncRun(ExternalAccount $account): void
    {
        MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'external_account_id' => $account->id, 'provider' => 'meta', 'status' => 'success',
            'window_start' => '2026-10-01', 'window_end' => '2026-10-07', 'started_at' => Carbon::now()->subDay(),
            'finished_at' => Carbon::now()->subDay()->addMinute(), 'metrics_upserted' => 5,
        ]);
    }
}
