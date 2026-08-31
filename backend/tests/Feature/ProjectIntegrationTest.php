<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves per-project integration isolation: switching projects changes the bound accounts, with no
 * leakage; plus shared-connection, detach-without-revoke, and revoke-impact behaviour.
 */
final class ProjectIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $tenant);
        $this->user->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project B', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    /** Establish a sandbox connection (shared at tenant level) and return an ad account id. */
    private function connectAndGetAdAccount(Project $project): string
    {
        $accounts = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/integrations/connect")
            ->assertCreated()
            ->assertJsonMissingPath('data.connection.encrypted_payload')
            ->json('data.accounts');

        return collect($accounts)->firstWhere('account_type', 'ad_account')['id'];
    }

    public function test_switching_projects_changes_bound_accounts_with_no_leakage(): void
    {
        $accountId = $this->connectAndGetAdAccount($this->projectA);

        // Bind the ad account to Project A only.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings", [
                'external_account_id' => $accountId, 'purpose' => 'advertising',
            ])->assertCreated();

        // Project A sees exactly one binding.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/integrations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.account.account_type', 'ad_account');

        // Project B sees NONE — switching projects changes the result, no leakage.
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectB->id}/integrations")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * ORCH-100 §I — one account feeds ONE project, and the second attempt is refused.
     *
     * This used to warn with a 409 and then share the account on `confirm=true`. That behaviour was
     * in the code rather than in any requirement, and the failure mode if it is wrong is a client
     * seeing another client's spend on their own report — so the safer reading wins. Detaching is how
     * an account moves; there is no way to have it in two places at once.
     */
    public function test_an_account_already_connected_to_a_project_cannot_be_connected_to_a_second(): void
    {
        $accountId = $this->connectAndGetAdAccount($this->projectA);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings", [
                'external_account_id' => $accountId, 'purpose' => 'advertising',
            ])->assertCreated();

        // Project B is refused, and told where the account already lives.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
                'external_account_id' => $accountId, 'purpose' => 'advertising',
            ])->assertStatus(409)->assertJsonPath('meta.assigned_project_id', $this->projectA->id);

        // `confirm` is not a way round it any more — the rule is the rule.
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
                'external_account_id' => $accountId, 'purpose' => 'advertising', 'confirm' => true,
            ])->assertStatus(409);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/integrations")->assertJsonCount(1, 'data');
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}/integrations")->assertJsonCount(0, 'data');
    }

    /**
     * Detaching frees the account, and leaves the OAuth connection alone.
     *
     * Rewritten for ORCH-100 §I: the account can no longer be in two projects at once, so the claim
     * this test carries is now the one that matters — detach releases it, the connection survives,
     * and the account can then be connected somewhere else.
     */
    public function test_detaching_frees_the_account_without_revoking_the_connection(): void
    {
        $accountId = $this->connectAndGetAdAccount($this->projectA);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings", [
            'external_account_id' => $accountId, 'purpose' => 'advertising',
        ])->assertCreated();

        $bindingA = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/integrations")->json('data.0.id');

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings/{$bindingA}")
            ->assertOk();

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/integrations")->assertJsonCount(0, 'data');

        // The OAuth connection is untouched — detaching an account is not giving up the authorisation.
        $this->assertDatabaseHas('provider_connections', ['status' => 'connected']);

        // And the freed account may now be connected to the other project.
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
            'external_account_id' => $accountId, 'purpose' => 'advertising',
        ])->assertCreated();
    }

    public function test_revoking_the_connection_disables_all_its_bindings(): void
    {
        $accountId = $this->connectAndGetAdAccount($this->projectA);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings", [
            'external_account_id' => $accountId, 'purpose' => 'advertising',
        ])->assertCreated();

        $connectionId = ProviderConnection::withoutGlobalScopes()->first()->id;

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/connections/{$connectionId}/revoke")
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertDatabaseHas('provider_connections', ['id' => $connectionId, 'status' => 'revoked']);
        $this->assertDatabaseHas('project_integration_bindings', ['external_account_id' => $accountId, 'is_active' => false]);
    }

    public function test_sync_records_a_run_and_updates_last_sync(): void
    {
        $accountId = $this->connectAndGetAdAccount($this->projectA);
        $bindingId = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings", [
            'external_account_id' => $accountId, 'purpose' => 'advertising',
        ])->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/integrations/bindings/{$bindingId}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertDatabaseHas('integration_sync_runs', ['binding_id' => $bindingId, 'status' => 'success']);
    }

    public function test_a_missing_or_other_tenant_project_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/projects/00000000-0000-0000-0000-000000000000/integrations')
            ->assertNotFound();
    }
}
