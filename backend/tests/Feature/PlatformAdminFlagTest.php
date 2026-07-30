<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `is_platform_admin` is the only key to the platform console (ADMIN-001).
 *
 * It grants every permission (`HasRoles::hasPermission` short-circuits on it), platform tenant scope,
 * unrestricted client access, and the ability to suspend any tenant. So the question this file exists
 * to answer is narrow and important: **can anything a customer controls ever set it?**
 *
 * The answer has to stay no through three routes — mass assignment, a tenant role, and any
 * customer-facing endpoint — because each is a different mistake and closing one does not close the
 * others.
 */
final class PlatformAdminFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Brand Co', 'slug' => 'brand-'.uniqid(), 'status' => 'active', 'account_type' => 'brand',
        ]);
    }

    /**
     * The hole this closes: the flag WAS in `$fillable`, so any `create()` or `update()` whose array
     * happened to carry the key would set it — one careless `update($request->validated())` away from
     * handing a customer the ability to suspend every tenant on the platform.
     */
    public function test_the_flag_cannot_be_set_by_mass_assignment(): void
    {
        $tenant = $this->tenant();

        $created = User::create([
            'name' => 'Sneaky', 'email' => 'sneaky@test.dev',
            'password' => 'secret123', 'is_platform_admin' => true,
        ]);

        $this->assertFalse($created->fresh()->is_platform_admin, 'create() must not set the flag');

        $created->update(['is_platform_admin' => true, 'name' => 'Still Sneaky']);

        $this->assertFalse($created->fresh()->is_platform_admin, 'update() must not set the flag');
        // …and the legitimate field in the same call still saved, so this is not blanket rejection.
        $this->assertSame('Still Sneaky', $created->fresh()->name);
    }

    /** Provisioning the owner still works — the flag is settable, just never by accident. */
    public function test_the_flag_can_still_be_set_deliberately(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@platform.test', 'password' => 'secret123']);
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->assertTrue($user->fresh()->is_platform_admin);
    }

    /**
     * No permission in the catalogue implies platform administration. A tenant can invent roles
     * freely, so if any grantable permission were treated as equivalent, every customer could mint
     * their own platform admin.
     */
    public function test_no_tenant_grantable_permission_confers_platform_administration(): void
    {
        $tenant = $this->tenant();

        $user = User::create([
            'name' => 'Everything', 'email' => 'everything@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Everything', 'slug' => 'everything-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::App, role: 'owner',
        ));

        $this->assertFalse($user->fresh()->is_platform_admin);
        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/admin/overview')->assertForbidden();
    }

    /** Registration creates a customer, never an owner — whatever the payload asks for. */
    public function test_registration_cannot_produce_a_platform_admin(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/register', [
                'tenant_name' => 'Sneaky Co', 'name' => 'Sneaky', 'email' => 'reg.sneaky@test.dev',
                'password' => 'secret1234', 'password_confirmation' => 'secret1234',
                'account_type' => 'brand', 'service' => 'paid_media',
                'is_platform_admin' => true,
            ])->assertCreated();

        $this->assertFalse(User::where('email', 'reg.sneaky@test.dev')->firstOrFail()->is_platform_admin);
    }

    /**
     * The personal-profile endpoint is the most plausible route: it is the one place a user updates
     * their OWN row, so a permissive update there would be self-promotion with no other actor.
     */
    public function test_updating_your_own_profile_cannot_set_the_flag(): void
    {
        $tenant = $this->tenant();
        $user = User::create([
            'name' => 'Self', 'email' => 'self@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::App, role: 'member',
        ));

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/account/profile', [
            'name' => 'Self Updated', 'is_platform_admin' => true,
        ]);

        $this->assertFalse($user->fresh()->is_platform_admin);
    }

    /**
     * Email verification is bypassed for the OWNER only — they are provisioned by whoever installs
     * the system, not self-registered. It must not have loosened the rule for anyone else, or every
     * customer would skip confirming their address.
     */
    public function test_email_verification_is_still_required_of_customers(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:5173'])
            ->postJson('/api/v1/auth/register', [
                'tenant_name' => 'Unverified Co', 'name' => 'New', 'email' => 'unverified@test.dev',
                'password' => 'secret1234', 'password_confirmation' => 'secret1234',
                'account_type' => 'brand', 'service' => 'paid_media',
            ])->assertCreated();

        $this->assertNull(
            User::where('email', 'unverified@test.dev')->firstOrFail()->email_verified_at,
            'a self-registered customer must still confirm their email',
        );
    }
}
