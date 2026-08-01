<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionProration;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing plan part-way through a period that has already been paid for (PAY-002).
 *
 * This was the largest unbuilt piece of the commercial contract: changing plan re-priced from the
 * next period and nothing computed a part-period credit, so an upgrade either gave away the rest of
 * the month or charged for it twice depending on which way you looked at it.
 *
 * The two claims worth holding in place are about money, and they pull in opposite directions:
 *
 *  1. **An upgrade is paid for before it applies.** Not on a redirect, not on a button — on a
 *     verified webhook, through the same single call site as every other activation here.
 *  2. **A downgrade never quietly keeps the difference.** It takes effect when the period the
 *     customer paid for ends, and until then they have everything they bought.
 */
final class PlanChangeProrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private SubscriptionPlan $small;

    private SubscriptionPlan $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-plan-change', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->holdingTenant($this->tenant->id);

        $this->owner = User::create([
            'name' => 'Owner', 'email' => 'owner@planchange.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->owner, $this->tenant, role: 'owner');

        /*
         * Permissions come from a ROLE, not from the membership's role string.
         *
         * The membership decides which workspace and portal; the role decides what may be done
         * inside it. Conflating them here would make this test pass on a permission check it never
         * actually exercised.
         */
        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-pc']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner->assignRole($ownerRole);

        $this->small = $this->plan('starter-pc', '100.00', '1000.00');
        $this->large = $this->plan('growth-pc', '300.00', '3000.00');
    }

    private function plan(string $code, string $monthly, string $annual): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'code' => $code, 'name' => ucfirst($code), 'price_monthly' => $monthly, 'price_annual' => $annual,
            'currency' => 'SAR', 'features' => [], 'limits' => ['projects' => 50],
            'is_active' => true, 'is_public' => true, 'trial_fee' => '9.00', 'trial_days' => 7,
        ]);
    }

    /** A subscription with exactly `$remaining` of its 30-day period left. */
    private function subscription(SubscriptionPlan $plan, string $unitAmount, int $remainingDays = 20): Subscription
    {
        $end = Carbon::now()->addDays($remainingDays);

        $subscription = new Subscription;
        $subscription->forceFill([
            'tenant_id' => $this->tenant->id,
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'billing_interval' => 'monthly',
            'unit_amount' => $unitAmount,
            'currency' => 'SAR',
            'current_period_start' => $end->copy()->subDays(30),
            'current_period_end' => $end,
            'seats' => 1,
        ])->save();

        return $subscription->refresh();
    }

    // ── The arithmetic, on its own ────────────────────────────────────────────────────────────

    /**
     * Two thirds of the period gone, so a third of each price counts.
     *
     * 20 of 30 days left: the customer is credited 20/30 of the 100.00 they paid, charged 20/30 of
     * the 300.00 they are moving to, and owes the difference — 200.00 × 20/30 = 133.33. Not the full
     * 300.00, which is what re-pricing from the next period would have taken.
     */
    public function test_an_upgrade_charges_only_the_difference_for_the_days_that_are_left(): void
    {
        $quote = app(SubscriptionProration::class)
            ->quote($this->subscription($this->small, '100.00', 20), $this->large, 'monthly');

        $this->assertSame('upgrade', $quote['direction']);
        $this->assertSame(20, $quote['remaining_days']);
        $this->assertSame(30, $quote['period_days']);
        $this->assertSame('66.67', $quote['credit']);        // 100.00 × 20/30
        $this->assertSame('200.00', $quote['prorated_new']); // 300.00 × 20/30
        $this->assertSame('133.33', $quote['due_now']);
        $this->assertSame('immediate', $quote['effective']);
    }

    /** Nothing is taken back on the way down, and nothing is owed. */
    public function test_a_downgrade_is_charged_nothing_and_waits_for_the_period_to_end(): void
    {
        $subscription = $this->subscription($this->large, '300.00', 20);
        $quote = app(SubscriptionProration::class)->quote($subscription, $this->small, 'monthly');

        $this->assertSame('downgrade', $quote['direction']);
        $this->assertSame('0.00', $quote['due_now']);
        $this->assertSame('period_end', $quote['effective']);
        $this->assertSame($subscription->current_period_end?->toIso8601String(), $quote['effective_at']);
    }

    /**
     * On the last day the difference rounds to nothing — and it is still an upgrade.
     *
     * Deciding the direction from the prorated difference rather than from the period prices would
     * make this a downgrade, and a downgrade takes effect later: the customer would be handed the
     * more expensive plan immediately, for nothing.
     */
    public function test_the_direction_comes_from_the_prices_not_from_what_is_left(): void
    {
        $quote = app(SubscriptionProration::class)
            ->quote($this->subscription($this->small, '100.00', 0), $this->large, 'monthly');

        $this->assertSame('upgrade', $quote['direction']);
        $this->assertSame('0.00', $quote['due_now']);
        $this->assertSame('immediate', $quote['effective']);
    }

    /** A term change at the same tier still prices the term being moved to. */
    public function test_moving_from_monthly_to_annual_prices_the_annual_term(): void
    {
        $quote = app(SubscriptionProration::class)
            ->quote($this->subscription($this->small, '100.00', 15), $this->small, 'annual');

        $this->assertSame('upgrade', $quote['direction']);
        $this->assertSame('1000.00', $quote['new_period_price']);
        $this->assertSame('450.00', $quote['due_now']); // (1000 − 100) × 15/30
    }

    // ── What actually happens to the subscription ─────────────────────────────────────────────

    /**
     * THE security claim: an upgrade does not apply when it is requested.
     *
     * A charge is opened and the plan stays exactly where it was. Everything the contract says about
     * activation happening only on a verified payment reduces to this assertion.
     */
    public function test_requesting_an_upgrade_opens_a_charge_and_moves_no_plan(): void
    {
        $subscription = $this->subscription($this->small, '100.00', 20);

        $result = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->large, 'monthly');

        $this->assertNotNull($result['charge']);
        $this->assertSame('133.33', (string) $result['charge']['payment']->amount);
        $this->assertSame('plan_change', $result['charge']['payment']->purpose);

        $subscription->refresh();
        $this->assertSame($this->small->getKey(), $subscription->plan_id, 'the plan moved before anyone paid');
        $this->assertSame('100.00', (string) $subscription->unit_amount);

        // Recorded as coming, on the columns that grant nothing — and with no date, because it
        // lands when the money does rather than on a clock.
        $this->assertSame($this->large->getKey(), $subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_change_at);
    }

    /** And when the payment is confirmed, it applies — priced at the new plan from then on. */
    public function test_a_confirmed_plan_change_payment_applies_the_plan(): void
    {
        $subscription = $this->subscription($this->small, '100.00', 20);
        $result = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->large, 'monthly');

        app(SubscriptionLifecycle::class)->planChangePaid($result['charge']['payment']);

        $subscription->refresh();
        $this->assertSame($this->large->getKey(), $subscription->plan_id);
        $this->assertSame('300.00', (string) $subscription->unit_amount, 'later renewals must charge the new price');
        $this->assertNull($subscription->scheduled_plan_id, 'the booking must be cleared once it is honoured');
    }

    /**
     * A plan change is NOT a renewal, and must not move the period end.
     *
     * `renewalPaid` pushes the period forward a whole month. Routing a part-period upgrade through
     * it would hand the customer a free month on top of the plan they bought — which is why the two
     * are separated at the point the webhook is applied rather than sharing a path.
     */
    public function test_paying_for_an_upgrade_does_not_buy_a_new_period(): void
    {
        $subscription = $this->subscription($this->small, '100.00', 20);
        $endBefore = $subscription->current_period_end;

        $result = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->large, 'monthly');
        app(SubscriptionLifecycle::class)->planChangePaid($result['charge']['payment']);

        $this->assertEquals(
            $endBefore?->toIso8601String(),
            $subscription->refresh()->current_period_end?->toIso8601String(),
        );
    }

    /** A downgrade books the change, charges nothing, and leaves the customer on what they paid for. */
    public function test_a_downgrade_books_the_change_and_opens_no_charge(): void
    {
        $subscription = $this->subscription($this->large, '300.00', 20);

        $result = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->small, 'monthly');

        $this->assertNull($result['charge']);
        $this->assertSame(0, SubscriptionPayment::query()->where('purpose', 'plan_change')->count());

        $subscription->refresh();
        $this->assertSame($this->large->getKey(), $subscription->plan_id, 'the customer paid for this period');
        $this->assertSame($this->small->getKey(), $subscription->scheduled_plan_id);
        $this->assertEquals(
            $subscription->current_period_end?->toIso8601String(),
            $subscription->scheduled_change_at?->toIso8601String(),
        );
    }

    /** …and it lands when the period it was waiting for is over. */
    public function test_a_booked_downgrade_applies_once_the_period_ends(): void
    {
        $subscription = $this->subscription($this->large, '300.00', 20);
        app(SubscriptionLifecycle::class)->changePlan($subscription, $this->small, 'monthly');

        // Not yet — the period is still running.
        $this->assertSame(0, app(SubscriptionLifecycle::class)->applyDueScheduledChanges());
        $this->assertSame($this->large->getKey(), $subscription->refresh()->plan_id);

        $applied = app(SubscriptionLifecycle::class)
            ->applyDueScheduledChanges(Carbon::now()->addDays(21));

        $this->assertSame(1, $applied);
        $subscription->refresh();
        $this->assertSame($this->small->getKey(), $subscription->plan_id);
        $this->assertSame('100.00', (string) $subscription->unit_amount);
        $this->assertNull($subscription->scheduled_plan_id);
    }

    /** A booked change can be withdrawn before it takes effect. */
    public function test_a_booked_change_can_be_withdrawn(): void
    {
        $subscription = $this->subscription($this->large, '300.00', 20);
        app(SubscriptionLifecycle::class)->changePlan($subscription, $this->small, 'monthly');

        app(SubscriptionLifecycle::class)->cancelScheduledChange($subscription->refresh(), 'changed their mind');

        $subscription->refresh();
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertSame($this->large->getKey(), $subscription->plan_id);
        $this->assertSame(0, app(SubscriptionLifecycle::class)->applyDueScheduledChanges(Carbon::now()->addDays(60)));
    }

    // ── No duplicate charge ───────────────────────────────────────────────────────────────────

    /**
     * Asking twice for the same upgrade is one charge, not two.
     *
     * The idempotency key is derived from what is being bought — this subscription, this period,
     * this plan and term — so a customer who reloads the page or double-submits is not billed twice.
     */
    public function test_requesting_the_same_upgrade_twice_opens_one_charge(): void
    {
        $subscription = $this->subscription($this->small, '100.00', 20);

        $first = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->large, 'monthly');
        $second = app(SubscriptionLifecycle::class)->changePlan($subscription->refresh(), $this->large, 'monthly');

        $this->assertSame(
            $first['charge']['payment']->getKey(),
            $second['charge']['payment']->getKey(),
        );
        $this->assertSame(1, SubscriptionPayment::query()->where('purpose', 'plan_change')->count());
    }

    /**
     * Changing your mind before paying gets a charge for the NEW choice, not the old one.
     *
     * Without the plan code in the key, the second request would resolve to the first plan's charge
     * — the right amount for the wrong thing.
     */
    public function test_a_different_choice_is_a_different_charge(): void
    {
        $third = $this->plan('scale-pc', '500.00', '5000.00');
        $subscription = $this->subscription($this->small, '100.00', 20);

        $a = app(SubscriptionLifecycle::class)->changePlan($subscription, $this->large, 'monthly');
        $b = app(SubscriptionLifecycle::class)->changePlan($subscription->refresh(), $third, 'monthly');

        $this->assertNotSame($a['charge']['payment']->getKey(), $b['charge']['payment']->getKey());
        $this->assertSame('growth-pc', $a['charge']['payment']->plan_code);
        $this->assertSame('scale-pc', $b['charge']['payment']->plan_code);
    }

    // ── The endpoint ──────────────────────────────────────────────────────────────────────────

    public function test_the_quote_endpoint_changes_nothing(): void
    {
        $this->subscription($this->small, '100.00', 20);

        $data = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change/quote', [
                'plan_code' => 'growth-pc', 'billing_interval' => 'monthly',
            ])->assertOk()->json('data');

        $this->assertSame('133.33', $data['quote']['due_now']);
        $this->assertSame('starter-pc', $data['from']['plan']);
        $this->assertSame(0, SubscriptionPayment::query()->count(), 'a quote must open no charge');
    }

    public function test_a_plan_that_is_not_for_sale_cannot_be_moved_to(): void
    {
        $this->subscription($this->small, '100.00', 20);
        SubscriptionPlan::create([
            'code' => 'internal-pc', 'name' => 'Internal', 'price_monthly' => '1.00',
            'currency' => 'SAR', 'features' => [], 'limits' => [], 'is_active' => true, 'is_public' => false,
        ]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change', [
                'plan_code' => 'internal-pc', 'billing_interval' => 'monthly',
            ])->assertNotFound();
    }

    /** Reading the price is not the same permission as agreeing to pay it. */
    public function test_committing_needs_more_than_permission_to_look(): void
    {
        $this->subscription($this->small, '100.00', 20);

        $member = User::create([
            'name' => 'Member', 'email' => 'member@planchange.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($member, $this->tenant, role: 'viewer');

        // Allowed to LOOK at the price, not to agree to pay it.
        $viewerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Viewer', 'slug' => 'viewer-pc']);
        $viewerRole->givePermissionTo('subscriptions.view');
        $member->assignRole($viewerRole);

        $this->actingAs($member, 'sanctum')
            ->postJson('/api/v1/subscriptions/plan-change', [
                'plan_code' => 'growth-pc', 'billing_interval' => 'monthly',
            ])->assertForbidden();
    }

    /** The current subscription reports a pending change without ever presenting it as the current one. */
    public function test_the_pending_change_is_reported_separately_from_the_plan_in_force(): void
    {
        $subscription = $this->subscription($this->large, '300.00', 20);
        app(SubscriptionLifecycle::class)->changePlan($subscription, $this->small, 'monthly');

        $data = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/subscriptions/current')->assertOk()->json('data');

        $this->assertSame('growth-pc', $data['plan']['code'], 'the plan in force is what was paid for');
        $this->assertSame('starter-pc', $data['subscription']['scheduled_change']['plan']);
        $this->assertFalse($data['subscription']['scheduled_change']['awaiting_payment']);
    }
}
