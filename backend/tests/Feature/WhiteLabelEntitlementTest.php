<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\Branding\Services\WhiteLabelEntitlement;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BRANDING-WHITE-LABEL-ENTITLEMENT — a stored preference is not a paid capability.
 *
 * `BrandingSetting.white_label` was written by the Branding Center and read straight back. The
 * model's own note said whether it is «actually permitted is a subscription concern», and that
 * concern existed nowhere — so ticking a box was the entire gate.
 *
 * NO PLAN IS NAMED in the code or in these tests. The catalogue carries a `white_label` boolean per
 * plan and operators edit it; naming «agency» would freeze a commercial decision into a deployment,
 * so every case below builds a plan and sets the FEATURE.
 */
final class WhiteLabelEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'WL', 'slug' => 'wl', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    private function plan(bool $whiteLabel, string $code = 'p1'): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'code' => $code,
            'name' => 'Plan',
            'price_monthly' => 100,
            'currency' => 'USD',
            'features' => ['white_label' => $whiteLabel],
            'limits' => [],
            'is_active' => true,
            'is_public' => true,
        ]);
    }

    private function setting(bool $requested): BrandingSetting
    {
        return BrandingSetting::create([
            'tenant_id' => $this->tenant->id,
            'scope' => 'tenant',
            'scope_id' => null,
            'white_label' => $requested,
        ]);
    }

    private function subject(): WhiteLabelEntitlement
    {
        return app(WhiteLabelEntitlement::class);
    }

    public function test_it_is_in_force_when_the_plan_grants_it_and_the_operator_asked(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(true), 'active');

        $this->assertTrue($this->subject()->effective($this->tenant, $this->setting(true)));
        $this->assertNull($this->subject()->reason($this->tenant, $this->setting(true)));
    }

    /** A plan that grants the feature does not turn it on. */
    public function test_a_granting_plan_alone_does_not_switch_it_on(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(true), 'active');

        $setting = $this->setting(false);

        $this->assertFalse($this->subject()->effective($this->tenant, $setting));
        $this->assertSame('not_requested', $this->subject()->reason($this->tenant, $setting));
    }

    /** And a tick does not buy the feature — this is the hole the row was opened for. */
    public function test_asking_for_it_on_a_plan_without_it_does_not_grant_it(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(false), 'active');

        $setting = $this->setting(true);

        $this->assertFalse($this->subject()->effective($this->tenant, $setting));
        $this->assertSame('plan_does_not_include_white_label', $this->subject()->reason($this->tenant, $setting));
    }

    /**
     * A trial carries its plan's features — a paid trial is a subscription, not a preview.
     */
    public function test_a_trialing_subscription_carries_the_feature(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(true), 'trialing');

        $this->assertTrue($this->subject()->effective($this->tenant, $this->setting(true)));
    }

    /**
     * A lapsed subscription does NOT. «Has a row» is not «is entitled», and treating it as such
     * would let a cancelled agency keep serving unbranded client reports indefinitely.
     */
    public function test_a_cancelled_or_past_due_subscription_does_not_carry_it(): void
    {
        foreach (['cancelled', 'past_due'] as $status) {
            app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(true, 'p-'.$status), $status);

            $setting = BrandingSetting::firstOrNew(['tenant_id' => $this->tenant->id, 'scope' => 'tenant', 'scope_id' => null]);
            $setting->white_label = true;
            $setting->save();

            $this->assertFalse($this->subject()->effective($this->tenant, $setting), "{$status} granted the feature");
            $this->assertSame('subscription_not_active', $this->subject()->reason($this->tenant, $setting));
        }
    }

    /** No subscription at all is not a free grant. */
    public function test_a_tenant_with_no_subscription_is_not_entitled(): void
    {
        $setting = $this->setting(true);

        $this->assertFalse($this->subject()->effective($this->tenant, $setting));
        $this->assertSame('no_subscription', $this->subject()->reason($this->tenant, $setting));
    }

    /**
     * The PREFERENCE survives a downgrade.
     *
     * Clearing the stored flag when a plan lapses would lose the operator's intent and force them to
     * re-tick a box after upgrading — and an upgrade should restore what they already asked for.
     */
    public function test_a_downgrade_removes_the_effect_and_keeps_the_preference(): void
    {
        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(true), 'active');
        $setting = $this->setting(true);
        $this->assertTrue($this->subject()->effective($this->tenant, $setting));

        app(SubscriptionService::class)->assignPlan($this->tenant, $this->plan(false, 'p-lower'), 'active');

        $this->assertFalse($this->subject()->effective($this->tenant, $setting->fresh()));
        $this->assertTrue($setting->fresh()->white_label, 'the operator’s preference was overwritten');
    }

    /** A plan whose features never mention white_label does not grant it by omission. */
    public function test_a_plan_that_does_not_mention_the_feature_does_not_grant_it(): void
    {
        $plan = SubscriptionPlan::create([
            'code' => 'silent', 'name' => 'Silent', 'price_monthly' => 1,
            'currency' => 'USD', 'features' => ['reports' => true], 'limits' => [],
            'is_active' => true, 'is_public' => true,
        ]);
        app(SubscriptionService::class)->assignPlan($this->tenant, $plan, 'active');

        $this->assertFalse($this->subject()->effective($this->tenant, $this->setting(true)));
    }
}
