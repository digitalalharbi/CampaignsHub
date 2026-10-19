<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Platform\Services\AgencyProvisioner;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AGENCY-PROVISION-001 — a second agency on the same platform is a second tenant, and nothing more.
 *
 * Three claims, each held to the data:
 *
 *  1. Provisioning creates an EMPTY, isolated tenant and grants nobody anything: the owner is invited,
 *     and access exists only after the holder of that verified address accepts.
 *  2. The invitation cannot be claimed by someone else — a different signed-in address, an unverified
 *     account, or a stranger with the link.
 *  3. Once claimed, the owner of two agencies sees each one's own rows through the real API, and their
 *     permissions in one never leak into the other.
 */
final class AgencyProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'digital@smartcode.sa';

    /** The SPA's origin: it is what makes Sanctum start the session a membership switch lives in. */
    private const SPA = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        Mail::fake();
    }

    private function provision(string $name = 'Smart Code', string $email = self::OWNER): array
    {
        $result = app(AgencyProvisioner::class)->provision($name, $email);
        app(TenantContext::class)->forget();

        return $result;
    }

    /** An established agency with a client and a project, owned by someone who already has an account. */
    private function establishedAgency(): array
    {
        $tenant = Tenant::create(['name' => 'Existing Agency', 'slug' => 'existing-agency', 'status' => 'active', 'account_type' => 'agency']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $owner = User::create(['name' => 'Existing Owner', 'email' => self::OWNER, 'password' => 'secret123']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        app(GrantMembership::class)->execute(new MembershipGrant(user: $owner, tenant: $tenant, portal: Portal::Agency, role: 'owner'));
        $owner->assignRole($role);

        $client = ClientWorkspace::create(['name' => 'Existing Client', 'slug' => 'ec-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'Existing Project', 'status' => 'active']);
        app(TenantContext::class)->forget();

        return [$tenant, $owner, $client, $project];
    }

    private function inviteToken(Tenant $tenant, string $email = self::OWNER): string
    {
        // The token exists only in the email; the test reads it back by replacing the stored hash.
        $token = Str::random(48);
        DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', $email)->whereNull('accepted_at')
            ->update(['token_hash' => hash('sha256', $token)]);

        return $token;
    }

    public function test_provisioning_creates_an_empty_active_agency_and_grants_nobody_access(): void
    {
        $r = $this->provision();
        $tenant = $r['tenant'];

        $this->assertTrue($r['created']);
        $this->assertSame('agency', $tenant->account_type);
        $this->assertTrue($tenant->isOperational());
        $this->assertSame(0, Membership::query()->where('tenant_id', $tenant->id)->count(), 'nobody is granted anything by provisioning');
        $this->assertSame(0, ClientWorkspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(), 'a new agency starts with no clients');
        $this->assertSame(0, Project::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertNotNull($r['invitation']);
        $this->assertFalse($r['owner_has_access']);
        $this->assertSame(1, DB::table('workspace_invitations')->where('tenant_id', $tenant->id)->where('email', self::OWNER)->whereNull('accepted_at')->count());
        $this->assertTrue(Role::where('tenant_id', $tenant->id)->where('slug', 'tenant-owner')->exists());
    }

    public function test_provisioning_twice_changes_nothing_and_issues_no_second_invitation(): void
    {
        $first = $this->provision();
        $second = $this->provision();

        $this->assertFalse($second['created']);
        $this->assertSame($first['tenant']->id, $second['tenant']->id);
        $this->assertNull($second['invitation'], 'an unexpired invitation is already pending');
        $this->assertSame(1, Tenant::where('slug', 'smart-code')->count());
        $this->assertSame(1, DB::table('workspace_invitations')->where('tenant_id', $first['tenant']->id)->count());
    }

    public function test_provisioning_never_touches_an_existing_agency_or_its_data(): void
    {
        [$existing, , $client, $project] = $this->establishedAgency();
        $before = [Tenant::count(), ClientWorkspace::withoutGlobalScopes()->count(), Project::withoutGlobalScopes()->count()];

        $this->provision();

        $this->assertSame($before[0] + 1, Tenant::count());
        $this->assertSame($before[1], ClientWorkspace::withoutGlobalScopes()->count());
        $this->assertSame($before[2], Project::withoutGlobalScopes()->count());
        $this->assertSame('Existing Agency', $existing->fresh()->name);
        $this->assertNotNull(ClientWorkspace::withoutGlobalScopes()->find($client->id));
        $this->assertNotNull(Project::withoutGlobalScopes()->find($project->id));
    }

    public function test_the_invitation_cannot_be_claimed_by_the_wrong_person(): void
    {
        $tenant = $this->provision()['tenant'];
        $token = $this->inviteToken($tenant);

        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@example.com', 'password' => 'secret123']);
        $stranger->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertStatus(422);

        // The right address, but never verified — typing an email at signup proves nothing.
        $unverified = User::create(['name' => 'Unverified', 'email' => self::OWNER, 'password' => 'secret123']);
        $this->actingAs($unverified, 'sanctum')->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertStatus(422);

        // No session at all: the link alone is not enough.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertStatus(401);

        $this->assertSame(0, Membership::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_an_owner_of_another_agency_claims_the_new_one_and_each_agency_stays_separate(): void
    {
        [$existing, $owner, $existingClient] = $this->establishedAgency();
        $smart = $this->provision()['tenant'];
        $token = $this->inviteToken($smart);

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertOk()
            ->assertJsonPath('data.membership_id', (string) Membership::query()->where('tenant_id', $smart->id)->where('user_id', $owner->id)->value('id'));
        $this->assertSame(1, Membership::query()->where('tenant_id', $smart->id)->where('user_id', $owner->id)->count());
        $this->assertSame(1, Membership::query()->where('tenant_id', $existing->id)->where('user_id', $owner->id)->count(), 'the existing agency membership is unchanged');

        // A claimed link is spent.
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertStatus(422);

        $smartMembership = Membership::query()->where('tenant_id', $smart->id)->where('user_id', $owner->id)->firstOrFail();
        $existingMembership = Membership::query()->where('tenant_id', $existing->id)->where('user_id', $owner->id)->firstOrFail();

        // Real CRUD inside Smart Code, through the API, switched into that agency.
        $created = $this->actingAs($owner, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $smartMembership->id])
            ->postJson('/api/v1/client-workspaces', ['name' => 'Smart Code Client', 'mode' => 'managed'])->assertCreated();
        $smartClientId = $created->json('data.id');

        $smartList = $this->actingAs($owner, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $smartMembership->id])
            ->getJson('/api/v1/client-workspaces')->assertOk()->json('data');
        $this->assertSame(['Smart Code Client'], collect($smartList)->pluck('name')->all(), 'Smart Code sees only its own client');

        $existingList = $this->actingAs($owner, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $existingMembership->id])
            ->getJson('/api/v1/client-workspaces')->assertOk()->json('data');
        $this->assertSame(['Existing Client'], collect($existingList)->pluck('name')->all(), 'the existing agency does not see Smart Code\'s client');

        // Reaching across by id is a 404 in both directions, even for the same person.
        $this->actingAs($owner, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $smartMembership->id])
            ->getJson("/api/v1/client-workspaces/{$existingClient->id}")->assertNotFound();
        $this->actingAs($owner, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $existingMembership->id])
            ->getJson("/api/v1/client-workspaces/{$smartClientId}")->assertNotFound();
    }

    public function test_a_role_in_one_agency_gives_no_permission_in_another(): void
    {
        [$existing, $owner] = $this->establishedAgency();
        $smart = $this->provision()['tenant'];

        // Limited in Smart Code, full owner in the existing agency.
        $limited = Role::create(['tenant_id' => $smart->id, 'name' => 'Viewer', 'slug' => 'viewer']);
        $owner->assignRole($limited);

        app(TenantContext::class)->setTenantId((string) $smart->id);
        $this->assertFalse($owner->fresh()->hasPermission('workspaces.create'), 'owning another agency must not make them a manager here');
        $this->assertFalse($owner->fresh()->hasRole('tenant-owner'));

        app(TenantContext::class)->setTenantId((string) $existing->id);
        $this->assertTrue($owner->fresh()->hasPermission('workspaces.create'));
        $this->assertTrue($owner->fresh()->hasRole('tenant-owner'));
    }

    public function test_a_new_person_claims_through_the_emailed_link_and_the_audit_trail_names_the_agency(): void
    {
        $r = $this->provision('Smart Code Two', 'someone@smartcode.sa');
        $token = $this->inviteToken($r['tenant'], 'someone@smartcode.sa');

        $this->assertFalse($this->getJson("/api/v1/invitations/{$token}")->assertOk()->json('data.existing_account'));
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Someone', 'password' => 'long-enough-1'])->assertCreated();

        $user = User::where('email', 'someone@smartcode.sa')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'following the emailed link proves the address');
        $this->assertSame(1, Membership::query()->where('tenant_id', $r['tenant']->id)->where('user_id', $user->id)->count());
        $this->assertGreaterThan(0, AuditLog::withoutGlobalScopes()->where('action', 'account.state.provisioned')->where('entity_id', (string) $r['tenant']->id)->count());
    }

    public function test_removing_a_shared_member_leaves_their_other_agency_intact(): void
    {
        [$existing, $owner] = $this->establishedAgency();
        $smart = $this->provision()['tenant'];
        $this->actingAs($owner, 'sanctum');
        $token = $this->inviteToken($smart);
        $this->postJson('/api/v1/invitations/accept-existing', ['token' => $token])->assertOk();

        // A second Smart Code owner removes the shared person from Smart Code only.
        app(TenantContext::class)->setTenantId((string) $smart->id);
        $other = User::create(['name' => 'Other Owner', 'email' => 'other@smartcode.sa', 'password' => 'secret123']);
        $other->forceFill(['email_verified_at' => now()])->save();
        $ownerRole = Role::where('tenant_id', $smart->id)->where('slug', 'tenant-owner')->firstOrFail();
        $other->assignRole($ownerRole);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $other, tenant: $smart, portal: Portal::Agency, role: 'owner'));
        app(TenantContext::class)->forget();

        $otherMembership = Membership::query()->where('user_id', $other->id)->firstOrFail();
        $this->actingAs($other, 'sanctum')->withHeaders(self::SPA)->withSession(['membership_id' => (string) $otherMembership->id])
            ->deleteJson('/api/v1/settings/team/'.$owner->uuid)->assertOk();

        $this->assertNotNull(User::find($owner->id), 'the account survives — it belongs to another agency too');
        $this->assertSame(0, Membership::query()->where('tenant_id', $smart->id)->where('user_id', $owner->id)->count());
        $this->assertSame(1, Membership::query()->where('tenant_id', $existing->id)->where('user_id', $owner->id)->count());
        $this->assertTrue($owner->roles()->where('roles.tenant_id', $existing->id)->exists(), 'their role in the other agency is untouched');
    }
}
