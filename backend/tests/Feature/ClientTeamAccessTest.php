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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Per-client team access: grant/remove, no-access denial, cross-tenant rejection, last-owner protection. */
final class ClientTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;      // clients.view_all + manage_team

    private User $buyer;      // NO view_all — access is membership-gated

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        // A media buyer can view clients they are a member of, but has NO agency-wide visibility.
        $buyerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Buyer', 'slug' => 'buyer']);
        $buyerRole->givePermissionTo('clients.view', 'clients.view_analytics');

        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->owner->assignRole($ownerRole);
        $this->buyer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Buyer', 'email' => 'b@a.test', 'password' => 'secret123']);
        $this->buyer->assignRole($buyerRole);
    }

    private function client(string $name = 'Acme'): ClientWorkspace
    {
        return ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => Str::slug($name.'-'.uniqid()),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    public function test_user_without_access_cannot_see_the_client(): void
    {
        $c = $this->client();
        // Buyer is not a member and has no view_all → command center is forbidden.
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}")->assertForbidden();
        // And the portfolio does not list it.
        $this->actingAs($this->buyer, 'sanctum')->getJson('/api/v1/app/clients')->assertJsonPath('meta.total', 0);
    }

    public function test_granting_access_lets_the_member_in_and_removing_denies_immediately(): void
    {
        $c = $this->client();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$c->id}/team", [
            'user_id' => $this->buyer->id, 'access_role' => 'media_buyer',
        ])->assertCreated();

        // Now the buyer can open the client (membership-gated access).
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}")->assertOk();

        // Remove access → the API denies immediately.
        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/v1/app/clients/{$c->id}/team/{$this->buyer->id}")->assertOk();
        $this->actingAs($this->buyer, 'sanctum')->getJson("/api/v1/app/clients/{$c->id}")->assertForbidden();
    }

    public function test_cannot_grant_access_to_a_user_from_another_tenant(): void
    {
        $c = $this->client();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        $foreign = User::create(['tenant_id' => $other->id, 'name' => 'F', 'email' => 'f@other.test', 'password' => 'secret123']);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$c->id}/team", [
            'user_id' => $foreign->id, 'access_role' => 'analyst',
        ])->assertStatus(422);
    }

    public function test_cannot_remove_the_last_owner(): void
    {
        $c = $this->client();
        DB::table('client_workspace_user')->insert([
            'client_workspace_id' => $c->id, 'user_id' => $this->buyer->id,
            'access_role' => 'client_owner', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/app/clients/{$c->id}/team/{$this->buyer->id}")->assertStatus(422);
    }

    public function test_project_restriction_must_belong_to_the_client(): void
    {
        $c = $this->client();
        $otherClient = $this->client('Other');
        $foreignProject = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $otherClient->id, 'name' => 'P', 'status' => 'active']);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/app/clients/{$c->id}/team", [
            'user_id' => $this->buyer->id, 'access_role' => 'analyst', 'project_ids' => [$foreignProject->id],
        ])->assertStatus(422);
    }
}
