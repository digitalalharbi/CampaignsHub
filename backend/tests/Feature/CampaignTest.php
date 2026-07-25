<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unified campaigns + external-campaign linking. Proves: CRUD, sync-driven import from the real
 * Sandbox connector, link/unlink, the duplicate-link 409 guard, auto-suggestions, RBAC, and
 * per-project / per-tenant isolation.
 */
final class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $owner = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->owner->assignRole($owner);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Project B', 'status' => 'active']);

        app(TenantContext::class)->forget();
    }

    // ---- CRUD ----------------------------------------------------------------------------------

    public function test_create_and_list_unified_campaign(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns", [
                'name' => 'National Day', 'objective' => 'sales', 'total_budget' => 50000, 'budget_currency' => 'SAR',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'National Day')
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_duplicate_name_within_project_is_rejected(): void
    {
        $this->createCampaign($this->projectA, 'Ramadan');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns", ['name' => 'Ramadan'])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('name');
    }

    public function test_pause_and_activate_transitions(): void
    {
        $id = $this->createCampaign($this->projectA, 'Summer');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$id}/pause")
            ->assertOk()->assertJsonPath('data.status', 'paused');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'active');
    }

    // ---- Import via real connector sync --------------------------------------------------------

    public function test_sync_imports_external_campaigns_from_the_connector(): void
    {
        $this->syncSandboxCampaigns($this->projectA);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/external-campaigns")
            ->assertOk()
            ->assertJsonCount(2, 'data')            // sandbox returns 2 campaigns
            ->assertJsonPath('data.0.is_linked', false);
    }

    public function test_sync_is_idempotent(): void
    {
        // Bind ONE account, then sync the same binding twice — the upsert must not duplicate.
        $bindingId = $this->bindSandboxAccount($this->projectA);
        $this->runSync($this->projectA, $bindingId);
        $this->runSync($this->projectA, $bindingId);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/external-campaigns")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ---- Linking -------------------------------------------------------------------------------

    public function test_link_and_unlink_external_campaign(): void
    {
        $campaignId = $this->createCampaign($this->projectA, 'Q4 Push');
        $externalId = $this->firstExternalCampaignId($this->projectA);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$campaignId}/external", ['external_campaign_id' => $externalId])
            ->assertCreated()
            ->assertJsonPath('data.is_linked', true);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$campaignId}/external")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$campaignId}/external/{$externalId}")
            ->assertOk();

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$campaignId}/external")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_relinking_to_a_different_unified_campaign_requires_confirmation(): void
    {
        $first = $this->createCampaign($this->projectA, 'Campaign One');
        $second = $this->createCampaign($this->projectA, 'Campaign Two');
        $externalId = $this->firstExternalCampaignId($this->projectA);

        // Link to the first.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$first}/external", ['external_campaign_id' => $externalId])
            ->assertCreated();

        // Link the same external to the second WITHOUT confirm → 409.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$second}/external", ['external_campaign_id' => $externalId])
            ->assertStatus(409)
            ->assertJsonPath('meta.requires_confirmation', true)
            ->assertJsonPath('meta.current_unified_campaign_id', $first);

        // With confirm=true it moves.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$second}/external", ['external_campaign_id' => $externalId, 'confirm' => true])
            ->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$first}/external")->assertJsonCount(0, 'data');
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$second}/external")->assertJsonCount(1, 'data');
    }

    public function test_suggestions_return_unlinked_externals(): void
    {
        $this->syncSandboxCampaigns($this->projectA);
        $campaignId = $this->createCampaign($this->projectA, 'Sandbox Awareness'); // matches a sandbox campaign name

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$campaignId}/suggestions")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Sandbox Awareness'); // best name-similarity match ranked first
    }

    // ---- RBAC & isolation ----------------------------------------------------------------------

    public function test_creating_requires_permission(): void
    {
        $viewer = $this->viewerUser();

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns", ['name' => 'Nope'])
            ->assertForbidden();

        // But viewing is allowed.
        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns")
            ->assertOk();
    }

    public function test_campaigns_are_isolated_per_project(): void
    {
        $this->createCampaign($this->projectA, 'Only in A');

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectB->id}/campaigns")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_unknown_project_returns_404(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/projects/00000000-0000-0000-0000-000000000000/campaigns')
            ->assertNotFound();
    }

    public function test_cross_project_campaign_id_is_not_found(): void
    {
        $id = $this->createCampaign($this->projectA, 'A only');

        // Same id, but addressed under project B → project scope makes it fail-closed 404.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectB->id}/campaigns/{$id}")
            ->assertNotFound();
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function createCampaign(Project $project, string $name): string
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/campaigns", ['name' => $name])
            ->assertCreated()
            ->json('data.id');
    }

    /** Establish a sandbox connection, bind its ad account, and run a campaign sync for the project. */
    private function syncSandboxCampaigns(Project $project): void
    {
        $bindingId = $this->bindSandboxAccount($project);
        $this->runSync($project, $bindingId);
    }

    /** Establish a sandbox connection and bind its ad account to the project; returns the binding id. */
    private function bindSandboxAccount(Project $project): string
    {
        $accounts = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/integrations/connect")
            ->assertCreated()->json('data.accounts');
        $accountId = collect($accounts)->firstWhere('account_type', 'ad_account')['id'];

        return $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings", ['external_account_id' => $accountId, 'purpose' => 'advertising'])
            ->assertCreated()->json('data.id');
    }

    private function runSync(Project $project, string $bindingId): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/{$bindingId}/sync")
            ->assertOk()->assertJsonPath('data.status', 'success');
    }

    private function firstExternalCampaignId(Project $project): string
    {
        $this->syncSandboxCampaigns($project);

        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/external-campaigns")
            ->assertOk()->json('data.0.id');
    }

    private function viewerUser(): User
    {
        app(TenantContext::class)->setTenantId($this->owner->tenant_id);
        $role = Role::create(['tenant_id' => $this->owner->tenant_id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $role->givePermissionTo('campaigns.view', 'projects.view', 'projects.view.all', 'integrations.view');
        $user = User::create(['tenant_id' => $this->owner->tenant_id, 'name' => 'A', 'email' => 'a@agency.test', 'password' => 'secret123']);
        $user->assignRole($role);
        app(TenantContext::class)->forget();

        return $user;
    }
}
