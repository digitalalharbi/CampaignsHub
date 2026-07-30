<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
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
 * The agency overview reports on the clients the operator may actually reach — and nothing else.
 *
 * A dashboard that totals the whole agency while the client list shows three of them is worse than no
 * dashboard: it leaks how much business exists, and it makes every figure unexplainable to the person
 * reading it. These tests hold the two to the same set.
 */
final class AgencyDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Dash Agency', 'slug' => 'dash-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name, string $status = 'active'): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => $status,
        ]);
    }

    /** A campaign belongs to a project, which belongs to the client — the real chain, not a shortcut. */
    private function campaign(ClientWorkspace $client, string $objective, string $status = 'active'): UnifiedCampaign
    {
        $project = Project::create([
            'tenant_id' => $this->agency->id,
            'client_workspace_id' => $client->id,
            'name' => 'P '.uniqid(),
            'status' => 'active',
        ]);

        return UnifiedCampaign::create([
            'tenant_id' => $this->agency->id,
            'client_workspace_id' => $client->id,
            'project_id' => $project->id,
            'name' => 'C '.uniqid(),
            'objective' => $objective,
            'status' => $status,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null): User
    {
        $user = User::create([
            'name' => 'Op', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create([
            'tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid(),
        ]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member',
            clientScopeIds: $clientScope,
        ));

        return $user;
    }

    public function test_an_unrestricted_operator_sees_the_whole_agency(): void
    {
        $a = $this->client('Alpha');
        $b = $this->client('Beta', 'needs_attention');
        $this->campaign($a, 'sales');
        $this->campaign($b, 'awareness', 'paused');

        $user = $this->operator('all@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clients.total', 2)
            ->assertJsonPath('data.clients.needs_attention', 1)
            ->assertJsonPath('data.campaigns.total', 2)
            ->assertJsonPath('data.campaigns.active', 1)
            ->assertJsonPath('data.campaigns.paused', 1)
            ->assertJsonPath('data.scope.is_restricted', false);
    }

    /** The numbers narrow with the scope — not just the list underneath them. */
    public function test_a_scoped_operator_sees_only_their_clients_figures(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs', 'needs_attention');
        $this->campaign($mine, 'sales');
        $this->campaign($theirs, 'awareness');
        $this->campaign($theirs, 'traffic');

        $user = $this->operator('scoped@test.dev', ['clients.view'], [(string) $mine->id]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clients.total', 1)
            // The other client needs attention; this operator must not learn that.
            ->assertJsonPath('data.clients.needs_attention', 0)
            ->assertJsonPath('data.campaigns.total', 1)
            ->assertJsonPath('data.scope.is_restricted', true)
            ->assertJsonPath('data.scope.client_count', 1);
    }

    /** Objective-aware, because one blended number across objectives means nothing. */
    public function test_campaigns_are_broken_down_by_objective(): void
    {
        $c = $this->client('Objectives');
        $this->campaign($c, 'sales');
        $this->campaign($c, 'sales');
        $this->campaign($c, 'awareness');

        $user = $this->operator('obj@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.campaigns.by_objective.sales', 2)
            ->assertJsonPath('data.campaigns.by_objective.awareness', 1);
    }

    /** An empty agency reports zeros — never demo figures standing in for real ones. */
    public function test_an_agency_with_no_clients_reports_zero_rather_than_sample_data(): void
    {
        $user = $this->operator('empty@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')
            ->assertOk()
            ->assertJsonPath('data.clients.total', 0)
            ->assertJsonPath('data.campaigns.total', 0)
            ->assertJsonPath('data.requests.open', 0);
    }

    /** The portal gate: an advertiser membership in the same tenant does not open the agency portal. */
    public function test_an_advertiser_membership_cannot_reach_the_agency_dashboard(): void
    {
        $user = User::create([
            'name' => 'Adv', 'email' => 'adv@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'adv-'.uniqid()]);
        $role->givePermissionTo('clients.view', ClientScopeResolver::ALL_CLIENTS);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::App, role: 'member',
        ));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')->assertForbidden();
    }

    public function test_a_user_without_the_clients_permission_is_refused(): void
    {
        $user = $this->operator('noperm@test.dev', ['campaigns.view']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')->assertForbidden();
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/agency/dashboard')->assertUnauthorized();
    }
}
