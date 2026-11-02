<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-1 — the membership's named client / project ceiling and `expires_at` on the project routes.
 *
 * The leak: `ResolveProject`, the project listing and the portfolio overview read `projects.view.all`
 * and never the membership's scopes, so an account manager confined to client A reached client B's
 * project in the same tenant. A time-limited contractor kept the portfolio figures after `expires_at`.
 */
final class Sec1ProjectReachTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Sec Agency', 'slug' => 'sec-agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);

        $this->clientA = $this->client('Alpha');
        $this->clientB = $this->client('Bravo');
        $this->projectA = $this->project($this->clientA, 'A1');
        $this->projectB = $this->project($this->clientB, 'B1');
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(ClientWorkspace $client, string $name): Project
    {
        return Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $clients
     * @param  list<string>|null  $projects
     */
    private function operator(string $email, array $permissions, ?array $clients = null, ?array $projects = null): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member',
            clientScopeIds: $clients, projectScopeIds: $projects,
        ));

        return $user;
    }

    /** @return list<string> */
    private function portfolioIds(User $user): array
    {
        return array_column($this->actingAs($user, 'sanctum')->getJson('/api/v1/portfolio/overview')->assertOk()->json('data.projects.items'), 'id');
    }

    /** @return list<string> */
    private function listedIds(User $user): array
    {
        return array_column($this->actingAs($user, 'sanctum')->getJson('/api/v1/projects')->assertOk()->json('data'), 'id');
    }

    public function test_a_client_scoped_manager_with_view_all_cannot_bind_another_clients_project(): void
    {
        $manager = $this->operator('mgr@sec.dev', ['projects.view', 'projects.view.all', 'tasks.view', 'clients.view'], [(string) $this->clientA->id]);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/tasks")->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}/tasks")->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}")->assertForbidden();
        $this->assertSame([(string) $this->projectA->id], $this->listedIds($manager));
        $this->assertSame([(string) $this->projectA->id], $this->portfolioIds($manager));
    }

    public function test_a_client_scoped_tenant_administrator_is_still_confined_to_the_named_client(): void
    {
        // settings.manage makes ProjectAbilities answer ALL — the ceiling must come first.
        $owner = $this->operator('owner@sec.dev', ['projects.view', 'projects.view.all', 'tasks.view', 'settings.manage'], [(string) $this->clientA->id]);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}/tasks")->assertForbidden();
    }

    public function test_a_project_scoped_member_with_view_all_is_confined_to_the_named_project(): void
    {
        $second = $this->project($this->clientA, 'A2');
        $user = $this->operator('proj@sec.dev', ['projects.view', 'projects.view.all', 'tasks.view'], null, [(string) $this->projectA->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/tasks")->assertOk();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/projects/{$second->id}/tasks")->assertForbidden();
        $this->assertSame([(string) $this->projectA->id], $this->listedIds($user));
    }

    public function test_an_unscoped_agency_wide_reader_still_reaches_every_project(): void
    {
        $owner = $this->operator('all@sec.dev', ['projects.view', 'projects.view.all', 'tasks.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}/tasks")->assertOk();
        $this->assertEqualsCanonicalizing([(string) $this->projectA->id, (string) $this->projectB->id], $this->listedIds($owner));
        $this->assertEqualsCanonicalizing([(string) $this->projectA->id, (string) $this->projectB->id], $this->portfolioIds($owner));
    }

    public function test_an_expired_project_membership_reaches_nothing_and_a_current_one_still_does(): void
    {
        $user = $this->operator('contractor@sec.dev', ['projects.view', 'tasks.view']);
        ProjectMembership::create([
            'project_id' => $this->projectA->id, 'user_id' => $user->id, 'role' => ProjectRole::MARKETING_MANAGER,
            'status' => 'active', 'expires_at' => now()->subDay(),
        ]);
        ProjectMembership::create([
            'project_id' => $this->projectB->id, 'user_id' => $user->id, 'role' => ProjectRole::MARKETING_MANAGER,
            'status' => 'active', 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/projects/{$this->projectA->id}/tasks")->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson("/api/v1/projects/{$this->projectB->id}/tasks")->assertOk();
        $this->assertSame([(string) $this->projectB->id], $this->listedIds($user));
        $this->assertSame([(string) $this->projectB->id], $this->portfolioIds($user));
    }
}
