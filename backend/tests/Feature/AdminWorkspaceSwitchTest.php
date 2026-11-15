<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\WorkspaceAssertion;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONS-ADMIN-SWITCH — one person, the admin panel and an agency workspace, without signing out.
 *
 * The platform admin's requests run in platform scope and never read the session's chosen workspace, so after choosing
 * their own agency workspace the agency pages (which name that workspace on every request) were refused, and the
 * memberships answer never said which workspace was current. Now the server re-verifies the chosen membership on every
 * request; it grants nothing the person does not hold, and the admin console keeps working beside it.
 */
final class AdminWorkspaceSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string> a browser on the SPA's origin: the session is real */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $agency;

    private Tenant $neighbour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = $this->tenant('Own Agency');
        $this->neighbour = $this->tenant('Neighbour Agency');
        app(TenantContext::class)->setTenantId($this->agency->id);
        ClientWorkspace::create(['tenant_id' => $this->agency->id, 'name' => 'Own Client', 'slug' => 'own-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        app(TenantContext::class)->setTenantId($this->neighbour->id);
        ClientWorkspace::create(['tenant_id' => $this->neighbour->id, 'name' => 'Their Client', 'slug' => 'their-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
    }

    private function admin(string $email): User
    {
        $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user;
    }

    private function agencyMember(User $user, Tenant $tenant): Membership
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'owner'], ['name' => 'Owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $membership = $this->grantMembership($user, $tenant, Portal::Agency, 'owner');
        $user->assignRole($role);

        return $membership;
    }

    public function test_an_admin_holding_an_agency_membership_switches_both_ways_without_signing_in_again(): void
    {
        $admin = $this->admin('both@platform.test');
        $own = $this->agencyMember($admin, $this->agency);

        $state = $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertOk();
        $state->assertJsonPath('data.platform_admin', true)->assertJsonPath('data.destination', '/admin');
        $this->assertSame([(string) $own->id], collect($state->json('data.memberships'))->pluck('id')->all());

        // Into the agency workspace…
        $this->actingAs($admin, 'web')->withHeaders($this->spa)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $own->id])
            ->assertOk()->assertJsonPath('data.current.id', (string) $own->id);

        // …which the header now names, and which every agency request runs in — named by the tab, as the SPA sends it.
        $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.current.id', (string) $own->id)->assertJsonPath('data.current.tenant.name', 'Own Agency');

        $clients = $this->actingAs($admin, 'web')->withHeaders($this->spa + [WorkspaceAssertion::HEADER => (string) $own->id])
            ->getJson('/api/v1/client-workspaces')->assertOk();
        $this->assertSame(['Own Client'], collect($clients->json('data'))->pluck('name')->all(), 'only the chosen workspace, never the neighbour');

        // …and back to the admin panel, same session, no sign-in.
        $this->actingAs($admin, 'web')->withHeaders($this->spa + [WorkspaceAssertion::HEADER => (string) $own->id])
            ->getJson('/api/v1/admin/tenants')->assertOk();
    }

    public function test_an_admin_without_the_second_membership_is_refused_and_gains_nothing(): void
    {
        $admin = $this->admin('only-admin@platform.test');
        $stranger = User::create(['name' => 'S', 'email' => 's@neighbour.test', 'password' => 'secret1234']);
        $theirs = $this->agencyMember($stranger, $this->neighbour);

        $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.memberships', [])->assertJsonPath('data.current', null);

        // Switching into somebody else's workspace by its id: refused, and nothing is remembered.
        $this->actingAs($admin, 'web')->withHeaders($this->spa)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $theirs->id])->assertForbidden();
        $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertJsonPath('data.current', null);

        // Naming it in the header changes nothing either.
        $this->actingAs($admin, 'web')->withHeaders($this->spa + [WorkspaceAssertion::HEADER => (string) $theirs->id])
            ->getJson('/api/v1/client-workspaces')->assertForbidden();
        $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/client-workspaces')->assertForbidden();
    }

    public function test_a_chosen_workspace_that_is_withdrawn_stops_answering_on_the_next_request(): void
    {
        $admin = $this->admin('lost@platform.test');
        $own = $this->agencyMember($admin, $this->agency);

        $this->actingAs($admin, 'web')->withHeaders($this->spa)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $own->id])->assertOk();

        // The membership is withdrawn after the choice: the session's id is re-checked, not trusted.
        Membership::query()->whereKey($own->id)->update(['status' => 'revoked']);

        $this->actingAs($admin, 'web')->withHeaders($this->spa + [WorkspaceAssertion::HEADER => (string) $own->id])
            ->getJson('/api/v1/client-workspaces')->assertForbidden();
        $this->actingAs($admin, 'web')->withHeaders($this->spa)->getJson('/api/v1/auth/memberships')->assertJsonPath('data.current', null);
    }
}
