<?php

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CAMPAIGN-VIEWS-001 — the project's change history across its campaigns, each event naming its
 * campaign; another project's events never appear.
 */
final class ProjectCampaignActivityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Project $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Q', 'status' => 'active']);
        $this->seed(PermissionSeeder::class);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'campaigns.view');
        $this->owner = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@w.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        app(TenantContext::class)->forget();
    }

    private function campaignIn(Project $project, string $name): UnifiedCampaign
    {
        app(ProjectContext::class)->setProjectId((string) $project->id);
        $c = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'name' => $name, 'objective' => 'sales', 'status' => 'draft', 'total_budget' => 1_000, 'budget_currency' => 'SAR',
        ]);
        app(ProjectContext::class)->forget();

        return $c;
    }

    private function event(UnifiedCampaign $c, string $action, array $before = [], array $after = []): void
    {
        AuditLog::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id, 'action' => $action,
            'entity_type' => 'unified_campaign', 'entity_id' => (string) $c->id, 'before' => $before ?: null, 'after' => $after ?: null,
        ]);
    }

    public function test_the_project_history_lists_every_campaign_event_with_its_campaign_name_and_nothing_from_elsewhere(): void
    {
        $a = $this->campaignIn($this->project, 'Spring');
        $b = $this->campaignIn($this->project, 'Summer');
        $elsewhere = $this->campaignIn($this->other, 'Not mine');
        $this->event($a, 'campaign.created');
        $this->event($b, 'campaign.updated', ['total_budget' => 1_000], ['total_budget' => 1_500]);
        $this->event($elsewhere, 'campaign.created');

        $events = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaign-activity")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['Spring', 'Summer'], array_column($events, 'campaign_name'));
        $updated = collect($events)->firstWhere('action', 'campaign.updated');
        $this->assertSame((string) $b->id, $updated['campaign_id']);
        $this->assertSame(1_500, $updated['after']['total_budget']);
        $this->assertSame('SAR', $updated['budget_currency']);
        $this->assertNotContains('Not mine', array_column($events, 'campaign_name'));
    }

    public function test_a_project_with_no_campaigns_answers_an_empty_list(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaign-activity")
            ->assertOk()
            ->assertJsonPath('data', []);
    }
}
