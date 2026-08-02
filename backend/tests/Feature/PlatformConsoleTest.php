<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The platform owner's console (ADMIN-001).
 *
 * Two lines are held here, and they pull in opposite directions:
 *
 *   The owner MUST see across tenants — that is the only thing this console is for, and every other
 *   surface in the product is forbidden from doing it.
 *
 *   Nobody else may reach it at all, and a tenant administrator must not be able to grant themselves
 *   entry by editing their own workspace's roles. So the gate is the `is_platform_admin` column and
 *   nothing else: not a role, not a permission, not an account type.
 */
final class PlatformConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenant(string $name, string $status = 'active', string $accountType = 'brand'): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(),
            'status' => $status, 'account_type' => $accountType,
        ]);
    }

    private function owner(): User
    {
        $user = User::create([
            'name' => 'Platform Owner', 'email' => 'owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    /** A tenant user with every permission their workspace can give — still not the owner. */
    private function tenantAdmin(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Tenant Admin', 'email' => 'admin@tenant.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Admin', 'slug' => 'admin-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::App, role: 'owner',
        ));

        return $user;
    }

    /**
     * The regression this console exists for: the person who owns the system signed in and was sent
     * to `/onboarding`, like a brand-new customer being asked to set up a workspace.
     */
    public function test_the_platform_owner_lands_in_their_console_rather_than_onboarding(): void
    {
        $owner = $this->owner();

        $this->assertSame('/admin', app(PortalResolver::class)->landingPathFor($owner));
    }

    /** A tenant user still lands in their own portal — the owner's route did not swallow theirs. */
    public function test_a_tenant_user_still_lands_in_their_own_portal(): void
    {
        $tenant = $this->tenant('Brand Co');
        $user = $this->tenantAdmin($tenant);

        $this->assertSame('/app/dashboard', app(PortalResolver::class)->landingPathFor($user));
    }

    /** Counting across tenants is the whole point, and the one thing no other surface may do. */
    public function test_the_overview_counts_across_every_tenant(): void
    {
        $this->tenant('One');
        $this->tenant('Two');
        $this->tenant('Three', 'suspended');

        $response = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/overview')->assertOk();

        $this->assertSame(3, $response->json('data.tenants.total'));
        $this->assertSame(2, $response->json('data.tenants.active'));
        $this->assertSame(1, $response->json('data.tenants.suspended'));
    }

    public function test_the_tenant_list_shows_every_tenant_with_its_size(): void
    {
        $a = $this->tenant('Alpha Co');
        $this->tenantAdmin($a);
        $this->tenant('Beta Co');

        $rows = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/tenants')
            ->assertOk()->json('data.tenants');

        $this->assertCount(2, $rows);
        $alpha = collect($rows)->firstWhere('name', 'Alpha Co');
        $this->assertSame(1, $alpha['people']);
    }

    /**
     * The gate. A tenant administrator holding EVERY permission their workspace can grant is still
     * refused — otherwise the console would be one role edit away from any customer.
     */
    public function test_a_tenant_administrator_with_every_permission_is_refused(): void
    {
        $tenant = $this->tenant('Brand Co');
        $admin = $this->tenantAdmin($tenant);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/overview')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/tenants')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit')->assertForbidden();
    }

    public function test_the_console_requires_a_session(): void
    {
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
    }

    /** Suspending is recorded with an actor and a reason — it locks people out of the product. */
    public function test_suspending_a_tenant_is_audited_with_a_reason(): void
    {
        $tenant = $this->tenant('Lapsed Co');
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", [
                'status' => 'suspended', 'reason' => 'Non-payment, 60 days.',
            ])->assertOk()->assertJsonPath('data.tenant.status', 'suspended');

        $this->assertSame('suspended', $tenant->fresh()->status);

        $entry = AuditLog::query()->where('action', 'platform.tenant.status_changed')->firstOrFail();
        $this->assertSame(['status' => 'active'], $entry->before);
        $this->assertSame(['status' => 'suspended'], $entry->after);
        $this->assertSame('Non-payment, 60 days.', $entry->reason);
        $this->assertSame($owner->id, $entry->user_id);
    }

    /** Suspending without saying why is refused: an audit entry with no reason explains nothing later. */
    public function test_suspending_without_a_reason_is_refused(): void
    {
        $tenant = $this->tenant('Lapsed Co');

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", ['status' => 'suspended'])
            ->assertStatus(422);

        $this->assertSame('active', $tenant->fresh()->status);
    }

    /**
     * Suspending the tenant that serves public request intake takes the form down for everyone.
     * Said plainly rather than blocked — the owner may genuinely mean it, but must not find out after.
     */
    public function test_suspending_the_public_portal_tenant_says_so(): void
    {
        $tenant = $this->tenant('Public Co');
        $tenant->forceFill(['is_default_portal' => true])->save();

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", [
                'status' => 'suspended', 'reason' => 'Investigating abuse.',
            ])->assertOk()->assertJsonPath('data.public_intake_affected', true);
    }

    /** Reactivating is not a suspension, so it needs no reason and must not be blocked by that rule. */
    public function test_reactivating_needs_no_reason(): void
    {
        $tenant = $this->tenant('Back Co', 'suspended');

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", ['status' => 'active'])
            ->assertOk();

        $this->assertSame('active', $tenant->fresh()->status);
    }

    /**
     * The console shows who can get in and through which portal — access is the owner's job. It does
     * NOT show the tenant's work, because owning the platform is not a reason to read a customer's
     * campaigns, and a console that made it effortless would see it happen without a decision.
     */
    public function test_a_tenants_detail_shows_access_but_not_its_work(): void
    {
        $tenant = $this->tenant('Detail Co');
        $this->tenantAdmin($tenant);

        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/v1/admin/tenants/{$tenant->id}")->assertOk()->json('data');

        $this->assertSame('Detail Co', $data['tenant']['name']);
        $this->assertSame('admin@tenant.test', $data['people'][0]['email']);
        $this->assertSame('app', $data['people'][0]['portal']);

        // No campaign, client or report payload anywhere in the response.
        foreach (['campaigns', 'reports', 'projects', 'clients'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data);
        }
    }

    public function test_an_unknown_tenant_is_not_found(): void
    {
        $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/tenants/'.Str::uuid()->toString())
            ->assertNotFound();
    }

    /** The trail crosses tenants too — a per-tenant view would hide the thing being audited. */
    public function test_the_audit_trail_spans_tenants(): void
    {
        $a = $this->tenant('A Co');
        $b = $this->tenant('B Co');
        $owner = $this->owner();

        foreach ([$a, $b] as $t) {
            $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/admin/tenants/{$t->id}/status", [
                'status' => 'suspended', 'reason' => 'Test.',
            ])->assertOk();
        }

        $entries = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/audit')
            ->assertOk()->json('data.entries');

        $tenantIds = collect($entries)->pluck('tenant_id')->unique();
        $this->assertCount(2, $tenantIds);
    }

    // ---- OPS-002: the money and entitlement trail --------------------------------------------

    /**
     * A subscription change is recorded WITHOUT anybody writing an audit line at the call site.
     *
     * `SubscriptionLifecycle` mutates subscriptions from about ten places, most of them running
     * unattended on a schedule. This asserts the guarantee is at the MODEL — suspend a subscription
     * through the lifecycle, and the trail has the before, the after and the reason the lifecycle
     * already computed and used to throw away.
     */
    public function test_a_subscription_change_is_audited_with_its_before_after_and_reason(): void
    {
        $tenant = $this->tenant('Payer Co');
        $plan = SubscriptionPlan::create([
            'code' => 'growth-'.uniqid(), 'name' => 'Growth', 'price_monthly' => 500,
            'currency' => 'SAR', 'is_active' => true,
        ]);

        app(TransitionAccountState::class)->provision($tenant, AccountState::Active, 'Test fixture.');

        app(TenantContext::class)->setTenantId($tenant->id);
        $subscription = Subscription::create([
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1,
            'billing_interval' => 'monthly', 'unit_amount' => 500, 'currency' => 'SAR',
        ]);
        app(SubscriptionLifecycle::class)->suspend($subscription, 'Card declined three times.');
        app(TenantContext::class)->forget();

        $entries = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/audit?category=subscriptions')
            ->assertOk()->json('data.entries');

        $change = collect($entries)->firstWhere('action', 'subscription.status_changed');
        $this->assertNotNull($change, 'a suspension must reach the trail');
        $this->assertSame('active', $change['before']['status']);
        $this->assertSame('suspended', $change['after']['status']);
        $this->assertSame('Card declined three times.', $change['reason']);
        $this->assertSame('subscriptions', $change['category']);
    }

    /**
     * The trail answers «who» with a name.
     *
     * A UUID is not an answer: the reader has to look it up somewhere else, which in practice means
     * the question goes unanswered. Both ids are kept — the name is for reading, the id for joining.
     */
    public function test_the_trail_names_the_actor_and_the_workspace(): void
    {
        $tenant = $this->tenant('Named Co');
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", [
            'status' => 'suspended', 'reason' => 'Test.',
        ])->assertOk();

        $entry = collect($this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/audit')
            ->assertOk()->json('data.entries'))->firstWhere('tenant_id', $tenant->id);

        $this->assertSame('Platform Owner', $entry['user_name']);
        $this->assertSame('Named Co', $entry['tenant_name']);
        $this->assertEquals($owner->id, $entry['user_id'], 'the id stays, for joining');
    }

    /**
     * Each of the four categories OPS-002 names can actually be asked for.
     *
     * The platform log runs to thousands of rows and `user.login` alone is over half of them. A trail
     * that cannot be narrowed to «subscriptions» or «payments» satisfies the requirement on paper and
     * not at a desk.
     */
    public function test_each_of_the_four_categories_can_be_filtered_for(): void
    {
        $owner = $this->owner();

        $categories = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/admin/audit')
            ->assertOk()->json('data.categories');

        $this->assertSame(['subscriptions', 'payments', 'approvals', 'permissions'], $categories);

        // A category returns only its own actions — never the whole log with a filter that did nothing.
        $tenant = $this->tenant('Filter Co');
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/admin/tenants/{$tenant->id}/status", [
            'status' => 'suspended', 'reason' => 'Test.',
        ])->assertOk();

        foreach (['subscriptions', 'payments'] as $category) {
            $entries = $this->actingAs($owner, 'sanctum')
                ->getJson("/api/v1/admin/audit?category={$category}")
                ->assertOk()->json('data.entries');

            foreach ($entries as $entry) {
                $this->assertSame($category, $entry['category'], 'a filter must not let other categories through');
            }
        }
    }

    /**
     * A payment's audit entry carries no way to reach the gateway.
     *
     * The trail says a charge of this amount moved to this state. It is not a place to keep a
     * checkout session or a provider identifier — an audit log that leaks one is worse than the gap
     * it was written to close.
     */
    public function test_a_payment_entry_records_the_money_and_not_the_gateway_session(): void
    {
        $tenant = $this->tenant('Charged Co');

        app(TenantContext::class)->setTenantId($tenant->id);
        $payment = SubscriptionPayment::create([
            'tenant_id' => $tenant->id, 'purpose' => 'trial', 'plan_code' => 'growth',
            'billing_interval' => 'monthly', 'provider' => 'moyasar', 'amount' => 500,
            'currency' => 'SAR', 'idempotency_key' => uniqid(),
            'provider_session_id' => 'sess_SECRET_DO_NOT_LOG',
        ]);
        // `status` is deliberately not fillable — a payload able to set it could mark itself paid —
        // so the gateway adapters write it with forceFill, and so does this.
        $payment->forceFill(['status' => 'failed', 'error' => 'Insufficient funds.'])->save();
        app(TenantContext::class)->forget();

        $entries = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/audit?category=payments')
            ->assertOk()->json('data.entries');

        $changed = collect($entries)->firstWhere('action', 'payment.status_changed');
        $this->assertNotNull($changed);
        $this->assertSame('failed', $changed['after']['status']);
        $this->assertSame('Insufficient funds.', $changed['reason'], 'the gateway’s own account of the refusal');

        $this->assertStringNotContainsString('sess_SECRET_DO_NOT_LOG', json_encode($entries));
    }
}
