<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRICING-CATALOGUE-001 — publishing the 2026-11 SAR catalogue changes nothing for anyone already subscribed.
 *
 * A launch-catalogue subscriber keeps their plan, price, currency and limits, still sees their plan as theirs, and is never
 * offered it as something anyone else can buy. A workspace with no subscription is not capped by the new catalogue either.
 */
final class PricingGrandfatheringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Legacy Co', 'slug' => 'legacy-co', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@legacy.test', 'password' => 'secret1234']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        app(TenantContext::class)->forget();
    }

    public function test_a_launch_subscriber_keeps_price_currency_and_limits_and_still_sees_their_plan(): void
    {
        $growth = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $sub = app(SubscriptionService::class)->assignPlan($this->tenant, $growth);

        $this->assertSame('49.00', (string) $sub->unit_amount);
        $this->assertSame('USD', $sub->currency);
        $this->assertSame(25, app(SubscriptionService::class)->effectiveLimit($this->tenant, 'projects'));

        $plans = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/subscriptions/plans')->assertOk()->json('data'));
        $mine = $plans->firstWhere('code', 'growth');
        $this->assertNotNull($mine, 'their own withdrawn plan is still shown to them');
        $this->assertTrue($mine['is_current']);
        $this->assertTrue($mine['grandfathered']);
        $this->assertSame('USD', $mine['currency']);
        $this->assertSame(['growth', 'solo', 'team', 'agency_2026'], $plans->pluck('code')->all(), 'no other withdrawn plan is offered');

        // …and the subscription itself is untouched by the catalogue's existence.
        $this->assertSame('49.00', (string) Subscription::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('unit_amount'));
    }

    public function test_a_launch_subscriber_cannot_move_back_onto_a_withdrawn_plan_once_they_leave_it(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, SubscriptionPlan::where('code', 'solo')->firstOrFail());

        $codes = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/subscriptions/plans')->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['solo', 'team', 'agency_2026'], $codes);
    }

    public function test_a_workspace_without_a_subscription_is_not_capped_by_the_new_catalogue(): void
    {
        $plan = app(SubscriptionService::class)->currentPlan($this->tenant);

        $this->assertSame('agency', $plan?->code, 'the most permissive plan is the uncapped one, not the most expensive figure');
        $this->assertNull(app(SubscriptionService::class)->effectiveLimit($this->tenant, 'projects'));
    }
}
