<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TEAM-CLIENT-COVERAGE-001 — «في صفحة الفريق أرى من يتابع كل عميل، والعميل بلا مدير مشروع يظهر
 * بعلامة» (Owner, MASTER_FINAL Appendix A task 7), inside the reader's own ceiling (contract S07).
 */
final class AgencyTeamCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Cov', 'slug' => 'cov', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => ($tenant ?? $this->agency)->id, 'name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /** @param list<string> $permissions */
    private function person(string $name, array $permissions, array $clientIds = [], ?Tenant $tenant = null): User
    {
        $tenant ??= $this->agency;
        $user = User::create(['name' => $name, 'email' => str($name)->slug().'@cov.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->grantMembership($user, $tenant, Portal::Agency, 'member', $clientIds);

        return $user;
    }

    public function test_each_client_names_who_follows_it_and_the_one_without_a_manager_is_flagged(): void
    {
        $ettifaq = $this->client('Ettifaq');
        $ajman = $this->client('Ajman');
        $owner = $this->person('Owner', ['users.view', 'users.update', 'clients.view', ClientScopeResolver::ALL_CLIENTS]);
        $sara = $this->person('Sara', ['clients.view'], [(string) $ettifaq->id]);
        Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $ettifaq->id, 'name' => 'Ettifaq', 'status' => 'active', 'account_manager_id' => $sara->id]);
        Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $ajman->id, 'name' => 'Ajman', 'status' => 'active']);

        $coverage = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/agency/team')->assertOk()->json('data.coverage'))->keyBy('name');

        $this->assertSame(['Sara'], $coverage['Ettifaq']['managers']);
        $this->assertSame(['Sara'], $coverage['Ettifaq']['followers']);
        $this->assertTrue($coverage['Ettifaq']['has_manager']);
        $this->assertFalse($coverage['Ajman']['has_manager']);
        $this->assertSame([], $coverage['Ajman']['followers']);
    }

    /** A scoped reader sees their own clients' rows and nothing about the rest — or another agency's. */
    public function test_the_coverage_stays_inside_the_readers_ceiling(): void
    {
        $mine = $this->client('Mine');
        $hidden = $this->client('Hidden');
        $elsewhere = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active', 'account_type' => 'agency']);
        $this->client('Foreign', $elsewhere);
        $lead = $this->person('Lead', ['users.view', 'clients.view'], [(string) $mine->id]);

        $names = collect($this->actingAs($lead, 'sanctum')->getJson('/api/v1/agency/team')->assertOk()->json('data.coverage'))->pluck('name')->all();

        $this->assertSame(['Mine'], $names);
        $this->assertNotContains((string) $hidden->name, $names);
    }

    /** Review finding 3: a confined reader whose role carries clients.view_all still sees only their clients. */
    public function test_a_named_client_list_outranks_the_all_clients_key(): void
    {
        $mine = $this->client('Mine');
        $this->client('Hidden');
        $lead = $this->person('Lead', ['users.view', 'clients.view', ClientScopeResolver::ALL_CLIENTS], [(string) $mine->id]);

        $names = collect($this->actingAs($lead, 'sanctum')->getJson('/api/v1/agency/team')->assertOk()->json('data.coverage'))->pluck('name')->all();

        $this->assertSame(['Mine'], $names);
    }
}
