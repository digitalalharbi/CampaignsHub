<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SUPERADMIN-WORKSPACE-001 — a workspace is created and handed to its owner from the console, with no SSH and no database edit.
 *
 * The owner is invited, never given a password: they set their own through a single-use, 72-hour link. The link reaches the operator
 * in the response (so a workspace can be handed over while mail is down) and nowhere else — not the audit trail, not a second call.
 */
final class PlatformWorkspaceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/tenants';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user;
    }

    private function tokenFrom(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);

        return (string) $q['token'];
    }

    public function test_only_a_platform_admin_creates_a_workspace(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'secret1234']);

        $this->actingAs($user, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test'])->assertForbidden();
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_a_workspace_is_created_with_the_chosen_name_plan_and_modules_and_its_owner_sets_their_own_password(): void
    {
        $plan = (string) \DB::table('subscription_plans')->where('is_active', true)->value('code');

        $data = $this->actingAs($this->admin(), 'sanctum')->postJson(self::URL, [
            'company_name' => 'Acme Media', 'workspace_name' => 'Acme HQ', 'owner_email' => 'Owner@Acme.test',
            'plan' => $plan, 'modules' => ['paid_media'],
        ])->assertOk()->json('data');

        $this->assertTrue($data['created']);
        $tenant = Tenant::query()->findOrFail($data['tenant']['id']);
        $this->assertSame($plan, $tenant->subscription_plan);
        $this->assertSame(['paid_media'], $tenant->enabled_modules);
        $this->assertSame('Acme HQ', Workspace::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('name'));
        $this->assertSame(72, $data['invitation']['expires_in_hours']);
        $link = (string) $data['invitation']['accept_link'];
        $this->assertStringContainsString('/invite/accept?token=', $link);

        // The link is a credential: it is in no audit row.
        $audit = AuditLog::query()->where('action', 'platform.tenant.provisioned')->sole();
        $this->assertStringNotContainsString($this->tokenFrom($link), json_encode($audit->getAttributes()));
        $this->assertSame('owner@acme.test', $audit->after['owner_email']);

        // The owner sets their own password through it, once.
        $this->postJson('/api/v1/invitations/accept', ['token' => $this->tokenFrom($link), 'name' => 'Owner', 'password' => 'a-long-secret-1234'])->assertCreated();
        $owner = User::query()->where('email', 'owner@acme.test')->sole();
        $this->assertTrue(Membership::query()->forUser($owner->id)->where('tenant_id', $tenant->id)->exists());
        $this->postJson('/api/v1/invitations/accept', ['token' => $this->tokenFrom($link), 'name' => 'Again', 'password' => 'a-long-secret-1234'])->assertStatus(422);
    }

    public function test_creating_the_same_workspace_twice_duplicates_nothing_and_issues_no_second_link(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test'])->assertOk();

        $again = $this->actingAs($admin, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test'])->assertOk()->json('data');

        $this->assertFalse($again['created']);
        $this->assertNull($again['invitation'], 'the first link is still live; a second would be a second credential');
        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(1, \DB::table('workspace_invitations')->count());
    }

    public function test_reissuing_the_owner_link_retires_the_old_one(): void
    {
        $admin = $this->admin();
        $first = $this->actingAs($admin, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test'])->assertOk()->json('data');

        $second = $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/'.$first['tenant']['id'].'/owner-invitation', ['owner_email' => 'o@acme.test'])
            ->assertOk()->json('data');

        $old = $this->tokenFrom((string) $first['invitation']['accept_link']);
        $new = $this->tokenFrom((string) $second['invitation']['accept_link']);
        $this->assertNotSame($old, $new);
        $this->postJson('/api/v1/invitations/accept', ['token' => $old, 'name' => 'Owner', 'password' => 'a-long-secret-1234'])
            ->assertStatus(422)->assertJsonMissingValidationErrors('name');
        $this->postJson('/api/v1/invitations/accept', ['token' => $new, 'name' => 'Owner', 'password' => 'a-long-secret-1234'])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.tenant.owner_invitation_reissued']);

        // Once the owner is in, a «resend» issues nothing.
        $after = $this->actingAs($admin, 'sanctum')->postJson(self::URL.'/'.$first['tenant']['id'].'/owner-invitation', ['owner_email' => 'o@acme.test'])->assertOk()->json('data');
        $this->assertTrue($after['owner_has_access']);
        $this->assertNull($after['invitation']);
    }

    public function test_an_existing_user_is_linked_rather_than_given_a_second_account(): void
    {
        User::create(['name' => 'Existing', 'email' => 'existing@x.test', 'password' => 'secret1234']);

        $data = $this->actingAs($this->admin(), 'sanctum')->postJson(self::URL, ['company_name' => 'Beta', 'owner_email' => 'existing@x.test'])->assertOk()->json('data');

        $this->assertNotNull($data['invitation']['accept_link']);
        $this->assertSame(1, User::query()->where('email', 'existing@x.test')->count());
    }

    public function test_an_unknown_plan_or_module_is_refused(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test', 'plan' => 'no-such-plan'])
            ->assertStatus(422)->assertJsonValidationErrors('plan');
        $this->actingAs($admin, 'sanctum')->postJson(self::URL, ['company_name' => 'Acme', 'owner_email' => 'o@acme.test', 'modules' => ['root_access']])
            ->assertStatus(422)->assertJsonValidationErrors('modules.0');
        $this->assertSame(0, Tenant::query()->count());
    }
}
