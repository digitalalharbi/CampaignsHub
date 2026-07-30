<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Invite → accept: join an EXISTING workspace with a role; guarded against dup/expired/reused/cross-tenant. */
final class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Role $memberRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->memberRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Analyst', 'slug' => 'analyst']);
        $this->memberRole->givePermissionTo('campaigns.view', 'reports.view');

        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'email' => 'o@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($ownerRole);
    }

    private function invite(string $email = 'member@a.test'): string
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', [
            'email' => $email, 'role_slug' => 'analyst',
        ])->assertCreated();
        $link = $res->json('data.dev_link');

        return explode('token=', $link)[1];
    }

    public function test_invite_creates_a_pending_invitation_with_honest_delivery(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', [
            'email' => 'member@a.test', 'role_slug' => 'analyst',
        ])->assertCreated()->assertJsonPath('data.delivery_status', 'awaiting_provider_credentials');
        $this->assertDatabaseHas('workspace_invitations', ['tenant_id' => $this->tenant->id, 'email' => 'member@a.test', 'accepted_at' => null]);
    }

    public function test_accepting_creates_the_user_in_the_existing_workspace_with_the_role(): void
    {
        $token = $this->invite();
        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.email', 'member@a.test');

        $res = $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Member', 'password' => 'secret1234'])
            ->assertCreated()->assertJsonPath('data.user.role_slug', 'analyst');

        $member = User::where('email', 'member@a.test')->firstOrFail();
        $this->assertSame($this->tenant->id, $member->tenant_id);       // joined the EXISTING tenant
        $this->assertNotNull($member->email_verified_at);               // verified by accepting the link
        $this->assertSame(1, Tenant::count());                          // NO new workspace was created
        $this->assertDatabaseHas('workspace_invitations', ['email' => 'member@a.test', 'accepted_user_id' => $member->id]);
    }

    public function test_duplicate_pending_invite_is_rejected(): void
    {
        $this->invite();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', [
            'email' => 'member@a.test', 'role_slug' => 'analyst',
        ])->assertStatus(422);
    }

    public function test_cannot_invite_an_existing_user_email(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/app/team/invitations', [
            'email' => 'o@a.test', 'role_slug' => 'analyst', // already a user
        ])->assertStatus(422);
    }

    public function test_expired_and_reused_tokens_are_rejected(): void
    {
        $token = $this->invite();
        // Reused (accepted) token can't be used again.
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Mem', 'password' => 'secret1234'])->assertCreated();
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Mem2', 'password' => 'secret1234'])->assertStatus(422);

        // Expired token.
        $token2 = $this->invite('later@a.test');
        DB::table('workspace_invitations')->where('email', 'later@a.test')->update(['expires_at' => now()->subDay()]);
        $this->postJson('/api/v1/invitations/accept', ['token' => $token2, 'name' => 'Late', 'password' => 'secret1234'])->assertStatus(422);
    }

    public function test_invite_requires_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'NoInvite', 'slug' => 'noinvite']);
        $role->givePermissionTo('campaigns.view');
        $u = User::create(['tenant_id' => $this->tenant->id, 'name' => 'X', 'email' => 'x@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($role);

        $this->actingAs($u, 'sanctum')->postJson('/api/v1/app/team/invitations', ['email' => 'z@a.test', 'role_slug' => 'analyst'])
            ->assertForbidden();
    }
}
