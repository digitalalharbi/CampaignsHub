<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator surface for client scopes.
 *
 * The rules being defended here were each established by a bug, so they are tested as behaviour
 * rather than as endpoint shapes: granting widens, withdrawing takes exactly one, replacing is the
 * only destructive verb, and nobody can hand out access they do not hold themselves.
 */
final class AgencyTeamScopesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Scope Agency', 'slug' => 'scope-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => ($tenant ?? $this->agency)->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->agency;

        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Op '.$email, 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member', clientScopeIds: $clientScope,
        ));

        return $user;
    }

    private function membershipOf(User $user): Membership
    {
        return Membership::query()->where('user_id', $user->id)
            ->where('portal', Portal::Agency->value)->firstOrFail();
    }

    /** An owner with `clients.view_all` administers the whole team. */
    private function owner(): User
    {
        return $this->operator('owner@scope.dev', [
            'users.view', 'users.update', 'clients.view', ClientScopeResolver::ALL_CLIENTS,
        ]);
    }

    public function test_the_team_list_names_each_members_clients(): void
    {
        $alpha = $this->client('Alpha');
        $owner = $this->owner();
        $manager = $this->operator('manager@scope.dev', ['clients.view'], [(string) $alpha->id]);

        $response = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/agency/team')->assertOk();

        $members = collect($response->json('data.members'));
        $row = $members->firstWhere('user.email', 'manager@scope.dev');

        $this->assertSame([(string) $alpha->id], $row['client_scope_ids']);
        $this->assertSame('Alpha', $row['clients'][0]['name']);
        $this->assertTrue($row['is_client_scoped']);

        // The owner holds no scope rows — and that is NOT the same as being confined to nothing.
        $ownerRow = $members->firstWhere('user.email', 'owner@scope.dev');
        $this->assertFalse($ownerRow['is_client_scoped']);
        $this->assertTrue($ownerRow['has_unrestricted_permission']);
    }

    /** Granting a second client keeps the first. This is the bug that made `add` a separate verb. */
    public function test_granting_another_client_keeps_the_ones_already_held(): void
    {
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $owner = $this->owner();
        $manager = $this->operator('m2@scope.dev', ['clients.view'], [(string) $alpha->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $beta->id]])
            ->assertOk();

        $held = $this->membershipOf($manager)->load('scopes')->clientScopeIds();
        sort($held);
        $expected = [(string) $alpha->id, (string) $beta->id];
        sort($expected);
        $this->assertSame($expected, $held);
    }

    /** Re-granting what they already have changes nothing — so re-inviting is safe. */
    public function test_granting_the_same_client_twice_is_a_no_op(): void
    {
        $alpha = $this->client('Alpha');
        $owner = $this->owner();
        $manager = $this->operator('m3@scope.dev', ['clients.view'], [(string) $alpha->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $alpha->id]])
            ->assertOk();

        $this->assertCount(1, $this->membershipOf($manager)->load('scopes')->clientScopeIds());
    }

    public function test_withdrawing_a_client_takes_only_that_one(): void
    {
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $owner = $this->owner();
        $manager = $this->operator('m4@scope.dev', ['clients.view'], [(string) $alpha->id, (string) $beta->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/agency/team/{$membership->id}/scopes/{$alpha->id}")
            ->assertOk();

        $this->assertSame([(string) $beta->id], $this->membershipOf($manager)->load('scopes')->clientScopeIds());
    }

    /** Replacing is destructive by design — and empty means NOTHING, never everything. */
    public function test_replacing_with_an_empty_list_leaves_the_member_reaching_nothing(): void
    {
        $alpha = $this->client('Alpha');
        $owner = $this->owner();
        $manager = $this->operator('m5@scope.dev', ['clients.view'], [(string) $alpha->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => []])
            ->assertOk();

        $this->assertSame([], $this->membershipOf($manager)->load('scopes')->clientScopeIds());

        // And that member now sees no clients at all — fail-closed, not fail-open.
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/app/clients')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * The rule that makes the ceiling mean anything: a scoped manager cannot hand out a client
     * they cannot reach themselves — otherwise they could widen a second account to the whole agency.
     */
    public function test_a_scoped_operator_cannot_grant_a_client_outside_their_own_reach(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $manager = $this->operator('lead@scope.dev', ['users.view', 'users.update', 'clients.view'], [(string) $mine->id]);
        $junior = $this->operator('junior@scope.dev', ['clients.view'], [(string) $mine->id]);
        $membership = $this->membershipOf($junior);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $theirs->id]])
            ->assertForbidden();

        $this->assertSame([(string) $mine->id], $this->membershipOf($junior)->load('scopes')->clientScopeIds());
    }

    /** Nor may replacing quietly strip a client the editor cannot see. */
    public function test_replacing_cannot_silently_drop_a_client_the_operator_cannot_see(): void
    {
        $mine = $this->client('Mine');
        $hidden = $this->client('Hidden');
        $manager = $this->operator('lead2@scope.dev', ['users.view', 'users.update', 'clients.view'], [(string) $mine->id]);
        $junior = $this->operator('junior2@scope.dev', ['clients.view'], [(string) $mine->id, (string) $hidden->id]);
        $membership = $this->membershipOf($junior);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $mine->id]])
            ->assertForbidden();

        $this->assertCount(2, $this->membershipOf($junior)->load('scopes')->clientScopeIds());
    }

    /** Widening your own ceiling is self-promotion whatever the role says. */
    public function test_an_operator_cannot_change_their_own_client_access(): void
    {
        $alpha = $this->client('Alpha');
        $manager = $this->operator('self@scope.dev', ['users.view', 'users.update', 'clients.view'], [(string) $alpha->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $alpha->id]])
            ->assertForbidden();
    }

    /** The list marks the caller's own row, so the UI never offers a control that would be refused. */
    public function test_the_team_list_marks_the_callers_own_membership(): void
    {
        $alpha = $this->client('Alpha');
        $manager = $this->operator('marked@scope.dev', ['users.view', 'users.update', 'clients.view'], [(string) $alpha->id]);
        $this->operator('other@scope.dev', ['clients.view'], [(string) $alpha->id]);

        $members = collect(
            $this->actingAs($manager, 'sanctum')->getJson('/api/v1/agency/team')->assertOk()->json('data.members')
        );

        $this->assertTrue($members->firstWhere('user.email', 'marked@scope.dev')['is_self']);
        $this->assertFalse($members->firstWhere('user.email', 'other@scope.dev')['is_self']);
    }

    /** A membership id from another agency is not found — probing it reveals nothing either way. */
    public function test_a_membership_in_another_tenant_is_not_found(): void
    {
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-agency-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        $stranger = $this->operator('stranger@other.dev', ['clients.view'], null, $other);
        $foreign = $this->membershipOf($stranger);

        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agency/team/{$foreign->id}/scopes", ['client_ids' => []])
            ->assertNotFound();
    }

    /** A client belonging to another tenant is never grantable, even to an unrestricted owner. */
    public function test_a_client_from_another_tenant_cannot_be_granted(): void
    {
        $other = Tenant::create([
            'name' => 'Other2', 'slug' => 'other2-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
        ]);
        $foreignClient = $this->client('Foreign', $other);

        $owner = $this->owner();
        $manager = $this->operator('m6@scope.dev', ['clients.view']);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => [(string) $foreignClient->id]])
            ->assertNotFound();

        $this->assertSame([], $this->membershipOf($manager)->load('scopes')->clientScopeIds());
    }

    public function test_the_team_surface_needs_its_permissions(): void
    {
        $viewerOnly = $this->operator('viewonly@scope.dev', ['users.view', 'clients.view']);
        $manager = $this->operator('m7@scope.dev', ['clients.view']);
        $membership = $this->membershipOf($manager);

        // Reading is allowed with users.view …
        $this->actingAs($viewerOnly, 'sanctum')->getJson('/api/v1/agency/team')->assertOk();
        // … changing is not, without users.update.
        $this->actingAs($viewerOnly, 'sanctum')
            ->postJson("/api/v1/agency/team/{$membership->id}/scopes", ['client_ids' => []])
            ->assertForbidden();

        $noPerm = $this->operator('noperm@scope.dev', ['clients.view']);
        $this->actingAs($noPerm, 'sanctum')->getJson('/api/v1/agency/team')->assertForbidden();
    }

    /** The portal gate holds here too: an advertiser membership does not open the agency team. */
    public function test_an_advertiser_membership_cannot_reach_the_agency_team(): void
    {
        $user = User::create([
            'tenant_id' => $this->agency->id, 'name' => 'Adv', 'email' => 'adv@scope.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'adv-'.uniqid()]);
        $role->givePermissionTo('users.view', 'users.update', 'clients.view', ClientScopeResolver::ALL_CLIENTS);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::App, role: 'member',
        ));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/team')->assertForbidden();
    }

    /** A scoped operator can only hand out the clients they can see — the list says so up front. */
    public function test_the_assignable_list_is_the_operators_own_reach(): void
    {
        $mine = $this->client('Mine');
        $this->client('Theirs');
        $manager = $this->operator('lead3@scope.dev', ['users.view', 'users.update', 'clients.view'], [(string) $mine->id]);

        $response = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/agency/team')->assertOk();

        $assignable = collect($response->json('data.assignable_clients'));
        $this->assertCount(1, $assignable);
        $this->assertSame('Mine', $assignable->first()['name']);
    }

    public function test_withdrawing_a_client_the_member_does_not_hold_is_not_found(): void
    {
        $alpha = $this->client('Alpha');
        $beta = $this->client('Beta');
        $owner = $this->owner();
        $manager = $this->operator('m8@scope.dev', ['clients.view'], [(string) $alpha->id]);
        $membership = $this->membershipOf($manager);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/v1/agency/team/{$membership->id}/scopes/{$beta->id}")
            ->assertNotFound();
    }
}
