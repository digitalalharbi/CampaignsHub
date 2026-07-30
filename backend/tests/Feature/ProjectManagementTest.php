<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['tenant_id' => $tenant->id, 'name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $tenant);
        $this->user->assignRole($role);
        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client', 'mode' => 'managed']);
        app(TenantContext::class)->forget();
    }

    public function test_create_update_and_clone_project(): void
    {
        $id = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $this->workspace->id, 'name' => 'Launch',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/projects/{$id}", ['status' => 'active', 'setup_completion' => 50])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.setup_completion', 50);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$id}/clone")
            ->assertCreated()->assertJsonPath('data.status', 'draft');

        $this->assertSame(2, Project::withoutGlobalScopes()->count());
    }

    public function test_archive_and_restore_status_transitions(): void
    {
        $id = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/projects', [
            'client_workspace_id' => $this->workspace->id, 'name' => 'X', 'status' => 'active',
        ])->json('data.id');

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$id}/archive")->assertOk()->assertJsonPath('data.status', 'archived');
        // Archived projects are hidden from the default list.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/projects')->assertJsonCount(0, 'data');
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/projects?include_archived=1')->assertJsonCount(1, 'data');

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$id}/restore")->assertOk()->assertJsonPath('data.status', 'active');
    }

    public function test_team_membership_and_last_admin_protection(): void
    {
        $project = $this->makeProject();
        $member = User::create(['tenant_id' => $this->user->tenant_id, 'name' => 'M', 'email' => 'm@agency.test', 'password' => 'secret123']);
        $this->grantMembership($member, \App\Domains\Tenancy\Models\Tenant::findOrFail($this->user->tenant_id));

        $membershipId = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$project->id}/team", [
            'user_id' => $member->id, 'role' => 'account_manager',
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$project->id}/team")
            ->assertOk()->assertJsonPath('data.0.role', 'account_manager');

        // Removing the only admin is blocked.
        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/projects/{$project->id}/team/{$membershipId}")
            ->assertStatus(422);
    }

    public function test_update_member_role_and_block_demoting_last_admin(): void
    {
        $project = $this->makeProject();
        $admin = User::create(['tenant_id' => $this->user->tenant_id, 'name' => 'A', 'email' => 'a@agency.test', 'password' => 'secret123']);
        $this->grantMembership($admin, \App\Domains\Tenancy\Models\Tenant::findOrFail($this->user->tenant_id));
        $member = User::create(['tenant_id' => $this->user->tenant_id, 'name' => 'M', 'email' => 'm@agency.test', 'password' => 'secret123']);
        $this->grantMembership($member, \App\Domains\Tenancy\Models\Tenant::findOrFail($this->user->tenant_id));

        $adminId = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$project->id}/team", ['user_id' => $admin->id, 'role' => 'account_manager'])->json('data.id');
        $memberId = $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/projects/{$project->id}/team", ['user_id' => $member->id, 'role' => 'analyst'])->json('data.id');

        // Promote analyst → account_manager (allowed).
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/projects/{$project->id}/team/{$memberId}", ['role' => 'account_manager'])
            ->assertOk()->assertJsonPath('data.role', 'account_manager');

        // Demote member back, then try to demote the remaining single admin → blocked.
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/projects/{$project->id}/team/{$memberId}", ['role' => 'analyst'])->assertOk();
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/projects/{$project->id}/team/{$adminId}", ['role' => 'analyst'])
            ->assertStatus(422);
    }

    public function test_project_overview_returns_real_counts_and_not_available_markers(): void
    {
        $project = $this->makeProject();
        app(TenantContext::class)->setTenantId($this->user->tenant_id);
        app(ProjectContext::class)->setProjectId($project->id);
        Task::create(['title' => 'T1', 'created_by' => $this->user->id]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$project->id}/overview")
            ->assertOk()
            ->assertJsonPath('data.project.name', 'P')
            ->assertJsonPath('data.built.open_tasks', 1)
            ->assertJsonFragment(['not_available_yet' => ['campaigns', 'spend', 'revenue', 'roas', 'tracking_health', 'onboarding_progress', 'active_campaigns', 'critical_alerts']]);
    }

    public function test_switching_projects_changes_tasks_and_notifications_with_no_leakage(): void
    {
        $a = $this->makeProject('Project A');
        $b = $this->makeProject('Project B');

        // Tasks + notifications scoped to each project (tenant + project context both set).
        app(TenantContext::class)->setTenantId($this->user->tenant_id);
        app(ProjectContext::class)->setProjectId($a->id);
        Task::create(['title' => 'Task A', 'created_by' => $this->user->id]);
        AppNotification::create(['user_id' => $this->user->id, 'project_id' => $a->id, 'type' => 't', 'title' => 'Notif A']);
        app(ProjectContext::class)->setProjectId($b->id);
        Task::create(['title' => 'Task B', 'created_by' => $this->user->id]);
        AppNotification::create(['user_id' => $this->user->id, 'project_id' => $b->id, 'type' => 't', 'title' => 'Notif B']);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        // Project A view shows only A's task + notification.
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$a->id}/tasks")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Task A');
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$a->id}/notifications")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Notif A');

        // Switching to Project B changes both — no leakage from A.
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$b->id}/tasks")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Task B');
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$b->id}/notifications")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Notif B');
    }

    private function makeProject(string $name = 'P'): Project
    {
        app(TenantContext::class)->setTenantId($this->user->tenant_id);
        $p = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => $name, 'status' => 'active']);
        app(TenantContext::class)->forget();

        return $p;
    }
}
