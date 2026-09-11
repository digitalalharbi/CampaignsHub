<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * RECOMMENDATIONS-ACTION-CENTER-001 — the decision is the SERVER's to refuse.
 *
 * The recommendations page now offers approve, reject and hide, and those controls follow
 * `reports.approve` rather than deciding it. That only holds if the endpoint refuses an operator who
 * does not hold it — hiding a button has never been a boundary, and a UI-only gate is the defect
 * this asserts against rather than the protection.
 */
final class RecommendationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private CampaignAnnotation $note;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active']);

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'C-'.uniqid(), 'objective' => 'sales', 'status' => 'active',
            'total_budget' => 1_000, 'budget_currency' => 'SAR',
        ]);

        $this->note = CampaignAnnotation::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'campaign_id' => $this->campaign->id, 'kind' => 'recommendation', 'status' => 'draft',
            'title' => 'Raise the budget', 'body' => null, 'priority' => 'high',
        ]);
    }

    /** @param list<string> $permissions */
    private function operator(array $permissions): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R-'.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);

        $user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant, Portal::App);
        $user->assignRole($role);

        return $user;
    }

    private function decide(User $user, string $status): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->patchJson(
            "/api/v1/projects/{$this->project->id}/campaigns/{$this->campaign->id}/annotations/{$this->note->id}",
            ['status' => $status],
        );
    }

    public function test_an_operator_holding_the_permission_may_approve(): void
    {
        $this->decide($this->operator(Permission::pluck('key')->all()), 'approved')->assertOk();

        $this->assertSame('approved', $this->note->fresh()->status);
    }

    /**
     * And an operator without it is refused BY THE SERVER.
     *
     * The page hides the control for this operator, and that is presentation. This is the boundary.
     */
    public function test_an_operator_without_the_permission_is_refused(): void
    {
        $without = $this->operator(['campaigns.view', 'campaigns.manage']);

        $this->decide($without, 'approved')->assertForbidden();

        $this->assertSame('draft', $this->note->fresh()->status, 'the refusal left the recommendation alone');
    }

    /** A status the contract does not name is refused rather than stored. */
    public function test_an_invented_status_is_refused(): void
    {
        $this->decide($this->operator(Permission::pluck('key')->all()), 'definitely-approved')
            ->assertStatus(422);

        $this->assertSame('draft', $this->note->fresh()->status);
    }
}
