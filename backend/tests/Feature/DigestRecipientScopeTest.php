<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Services\DigestScope;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DIGEST-RECIPIENT-SCOPE-001 — Owner 2026-11-23: «كل مدير مشروع يوصله أداء مشروعه … وفق صلاحياته فقط 100%».
 *
 * A project manager added to his project through a project membership (no client named on his workspace membership, no
 * tenant-wide grant) reached nothing in the digest, while every page of the product opened that project for him. The digest
 * now follows the same rule as `ProjectReach`: his CURRENT project memberships, live projects of this tenant, nothing more.
 */
final class DigestRecipientScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $mine;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'mode' => 'managed']);
        $this->mine = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Mine', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Other', 'status' => 'active']);
    }

    public function test_a_project_manager_gets_exactly_the_projects_he_is_a_member_of(): void
    {
        $pm = $this->member();
        $this->join($pm, $this->mine);

        $this->assertSame([(string) $this->mine->id], app(DigestScope::class)->projectIdsFor($pm, (string) $this->tenant->id));
    }

    public function test_a_lapsed_or_inactive_project_membership_reaches_nothing(): void
    {
        $lapsed = $this->member();
        $this->join($lapsed, $this->mine, ['expires_at' => now()->subDay()]);
        $removed = $this->member();
        $this->join($removed, $this->mine, ['status' => 'removed']);

        $this->assertSame([], app(DigestScope::class)->projectIdsFor($lapsed, (string) $this->tenant->id));
        $this->assertSame([], app(DigestScope::class)->projectIdsFor($removed, (string) $this->tenant->id));
    }

    public function test_a_member_of_no_project_reaches_nothing_never_everything(): void
    {
        $this->assertSame([], app(DigestScope::class)->projectIdsFor($this->member(), (string) $this->tenant->id));
    }

    private function member(): User
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R'.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo('projects.view');
        $user = User::create(['name' => 'PM', 'email' => uniqid().'@ag.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function join(User $user, Project $project, array $extra = []): void
    {
        ProjectMembership::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'role' => 'account_manager', 'status' => 'active',
        ], $extra));
    }
}
