<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
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

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $owner = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $tenant);
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

    /**
     * A synced campaign arrives LINKED — CAMPAIGNS-VISIBLE-001.
     *
     * This asserted `is_linked: false`, which described the defect rather than a requirement: the
     * Campaigns page lists `unified_campaigns`, nothing in the sync path had ever created one, and so
     * a real first sync left that page empty with no error and nothing to press. Each imported
     * campaign is now adopted into a visible one on first import, and stays linked to it until
     * somebody decides otherwise.
     */
    public function test_sync_imports_external_campaigns_from_the_connector(): void
    {
        $this->syncSandboxCampaigns($this->projectA);

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/external-campaigns")
            ->assertOk()
            ->assertJsonCount(2, 'data')            // sandbox returns 2 campaigns
            ->assertJsonPath('data.0.is_linked', true);

        // And they are visible where somebody looks for campaigns, which is the point of adopting them.
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/campaigns")
            ->assertOk()
            ->assertJsonCount(2, 'data');
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

    /**
     * Linking to a campaign somebody typed, and unlinking again.
     *
     * The external now arrives adopted, so this starts by unlinking it — which is also the real
     * journey: a person merging two platforms' campaigns under one name is moving them OFF the
     * placeholders adoption created, not linking from nothing.
     */
    public function test_link_and_unlink_external_campaign(): void
    {
        $campaignId = $this->createCampaign($this->projectA, 'Q4 Push');
        $externalId = $this->firstExternalCampaignId($this->projectA);
        $this->unlinkFromAdoption($externalId);

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
        // Off its adopted placeholder first, so what is being tested is the relink and not the adoption.
        $this->unlinkFromAdoption($externalId);

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

    /**
     * Suggestions are UNLINKED externals, and adoption does not empty that list for ever.
     *
     * Adoption happens on FIRST import only — never on `unified_campaign_id === null`, which would
     * have silently undone every deliberate unlink on the next sweep and left this list permanently
     * empty. Unlinking is what puts a campaign back in front of somebody deciding where it belongs.
     */
    public function test_suggestions_return_unlinked_externals(): void
    {
        $this->syncSandboxCampaigns($this->projectA);

        foreach ($this->externalCampaignIds($this->projectA) as $id) {
            $this->unlinkFromAdoption($id);
        }

        /*
         * A name CLOSE to a sandbox campaign, not identical to it.
         *
         * `unified_campaigns` is unique on `(project_id, name)`, and adoption has already taken the
         * exact name — so reusing it verbatim is now refused, correctly: two campaigns with one name
         * in one project is a state the product does not have. Similarity ranking is what is under
         * test here, and it does not need an exact match to demonstrate.
         */
        $campaignId = $this->createCampaign($this->projectA, 'Sandbox Awareness Push');

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

    /**
     * Detach an external campaign from the placeholder adoption created for it.
     *
     * Uses the same endpoint a person would: the adopted unified campaign is read back from the
     * external row rather than guessed at, so this stays correct if adoption's naming ever changes.
     */
    private function unlinkFromAdoption(string $externalId): void
    {
        $unifiedId = (string) ExternalCampaign::withoutGlobalScopes()
            ->whereKey($externalId)
            ->value('unified_campaign_id');

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$unifiedId}/external/{$externalId}")
            ->assertOk();
    }

    /** @return list<string> */
    private function externalCampaignIds(Project $project): array
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$project->id}/external-campaigns")
            ->assertOk()
            ->json('data.*.id');
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
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $role->givePermissionTo('campaigns.view', 'projects.view', 'projects.view.all', 'integrations.view');
        $user = User::create(['name' => 'A', 'email' => 'a@agency.test', 'password' => 'secret123']);
        $this->grantMembership($user, Tenant::findOrFail($this->tenant->id));
        $user->assignRole($role);
        app(TenantContext::class)->forget();

        return $user;
    }
}
