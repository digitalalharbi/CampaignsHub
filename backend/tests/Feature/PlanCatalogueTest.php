<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * Plans as an engine rather than as literals (PLAN-001).
 *
 * The contract's requirement is that the catalogue is central and editable, and the reason is
 * practical: the price a visitor is shown, the amount a checkout charges, the limits the backend
 * enforces and the date a renewal falls due all have to be the same statement. Where each reads its
 * own literal they drift, and the first symptom is a customer charged an amount nobody quoted.
 */
final class PlanCatalogueTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function catalogue(): PlanCatalogue
    {
        return app(PlanCatalogue::class);
    }

    private function platformAdmin(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'platform@plans.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    // ── The public catalogue ──────────────────────────────────────────────────────────────────

    public function test_the_catalogue_is_readable_before_anyone_has_an_account(): void
    {
        $res = $this->getJson('/api/v1/plans')->assertOk();

        $codes = array_column((array) $res->json('data.plans'), 'code');
        $this->assertSame(['starter', 'growth', 'agency'], $codes, 'ordered by the catalogue, not by id');

        /*
         * Both terms are published, because both are sold — read from the catalogue rather than
         * hard-coded, so re-pricing is a commercial decision and not a test failure. The literals
         * that were here (`499.00`, `4990.00`, `7`) had to be edited by hand the moment the owner
         * moved the catalogue to USD and to a thirty-day introductory month.
         */
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $growth = collect($res->json('data.plans'))->firstWhere('code', 'growth');
        $this->assertSame((string) $plan->price_monthly, $growth['price_monthly']);
        $this->assertSame((string) $plan->price_annual, $growth['price_annual']);
        $this->assertSame($plan->trial_days, $growth['trial_days']);
        $this->assertSame('USD', $growth['currency'], 'subscriptions are sold in USD (PAY-AUDIT-002)');
    }

    /**
     * Withdrawing a plan from SALE is not switching it off.
     *
     * Two fields rather than one, because a plan we have stopped selling has to keep working for
     * everyone already paying for it.
     */
    public function test_a_withdrawn_plan_stops_being_offered_without_being_switched_off(): void
    {
        SubscriptionPlan::where('code', 'growth')->update(['is_public' => false]);

        $codes = array_column((array) $this->getJson('/api/v1/plans')->json('data.plans'), 'code');
        $this->assertNotContains('growth', $codes);

        // …and it is still a live plan for anyone on it.
        $this->assertTrue(SubscriptionPlan::where('code', 'growth')->firstOrFail()->is_active);
        $this->assertNotNull($this->catalogue()->byCode('growth'));
        $this->assertFalse($this->catalogue()->isOffered('growth'));
    }

    // ── Quotes ────────────────────────────────────────────────────────────────────────────────

    /**
     * The introductory month quotes the INTRODUCTORY price now and the full price later.
     *
     * Quoting the subscription price as "due now" would misstate the charge the customer is about to
     * authorise — the difference between a symbolic first month and a full month's billing.
     *
     * Asked on the MONTHLY term, because the introductory month is a monthly offer: this test used to
     * ask for `interval=annual` and expect the introductory price, which is the defect PAY-AUDIT-003
     * removed — an annual buyer was quoted a cheap month and a renewal thirty days later.
     */
    public function test_the_introductory_quote_separates_what_is_taken_now_from_what_falls_due_later(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->getJson('/api/v1/plans/growth/quote?interval=monthly')->assertOk()
            ->assertJsonPath('data.quote.due_now', (string) $plan->trial_fee)
            ->assertJsonPath('data.quote.due_later', (string) $plan->price_monthly)
            ->assertJsonPath('data.quote.renews_in_days', $plan->trial_days)
            ->assertJsonPath('data.quote.trial_days', $plan->trial_days);
    }

    /** And the ANNUAL term never passes through it — see PlanCommercialTermsTest for the full case. */
    public function test_the_annual_term_skips_the_introductory_month(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->getJson('/api/v1/plans/growth/quote?interval=annual')->assertOk()
            ->assertJsonPath('data.quote.due_now', (string) $plan->price_annual)
            ->assertJsonPath('data.quote.due_later', null)
            ->assertJsonPath('data.quote.renews_in_days', 365);
    }

    /**
     * A plan with no introductory month charges its own price today, and renews on its own term.
     *
     * Every seeded plan now opens with one, so this withdraws it from `growth` first — which is also
     * what an owner does from `/admin` when they end an offer.
     */
    public function test_a_plan_without_an_introductory_month_charges_its_price_today(): void
    {
        SubscriptionPlan::where('code', 'growth')->update(['trial_days' => 0]);
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->getJson('/api/v1/plans/growth/quote?interval=monthly')->assertOk()
            ->assertJsonPath('data.quote.due_now', (string) $plan->price_monthly)
            ->assertJsonPath('data.quote.due_later', null)
            ->assertJsonPath('data.quote.renews_in_days', 30);
    }

    /**
     * A term a plan is not sold on has NO price — it does not fall back to the other one.
     *
     * Charging a year's fee for a month, or a month's for a year, is exactly what a silent default
     * here would eventually do.
     */
    public function test_a_term_a_plan_is_not_sold_on_has_no_price(): void
    {
        /*
         * Every seeded plan is now sold on both terms — «البداية» included, since PLAN-PAID-001 gave
         * it a price. So the rule is demonstrated on a plan that is deliberately withdrawn from the
         * annual term, which is what `price_annual = null` means and what the console writes when an
         * owner takes a plan off the yearly price list.
         */
        $monthlyOnly = SubscriptionPlan::query()->create([
            'code' => 'monthly-only', 'name' => 'Monthly Only', 'name_ar' => 'شهري فقط',
            'price_monthly' => 199, 'price_annual' => null, 'currency' => 'USD',
            'trial_fee' => 0, 'trial_days' => 0, 'is_active' => true, 'is_public' => true, 'sort_order' => 90,
        ]);

        $this->getJson('/api/v1/plans/monthly-only/quote?interval=annual')->assertStatus(422);

        $this->assertNull($monthlyOnly->priceFor('annual'));
        $this->assertNull($this->catalogue()->quote($monthlyOnly, 'annual'));

        // And the priced term still answers, so the refusal above is about the TERM and not the plan.
        $this->getJson('/api/v1/plans/monthly-only/quote?interval=monthly')->assertOk()
            ->assertJsonPath('data.quote.due_now', '199.00');
    }

    /** «البداية» is sold, not given away (PLAN-PAID-001). */
    public function test_the_entry_plan_is_priced_on_both_terms(): void
    {
        $res = $this->getJson('/api/v1/plans')->assertOk();

        $plan = SubscriptionPlan::where('code', 'starter')->firstOrFail();
        $starter = collect($res->json('data.plans'))->firstWhere('code', 'starter');
        $this->assertSame((string) $plan->price_monthly, $starter['price_monthly']);
        $this->assertSame((string) $plan->price_annual, $starter['price_annual'], 'the annual term must be published before payment');
        $this->assertGreaterThan(0, (float) $starter['price_monthly'], 'there is no free tier');

        // What the plan is sold ON, as data rather than as marketing copy.
        $this->assertTrue((bool) $starter['features']['campaign_tracking']);
        $this->assertTrue((bool) $starter['features']['reports']);

        // The quote a visitor is shown before paying names the whole annual amount, not a monthly one.
        $this->getJson('/api/v1/plans/starter/quote?interval=annual')->assertOk()
            ->assertJsonPath('data.quote.due_now', (string) $plan->price_annual)
            ->assertJsonPath('data.quote.renews_in_days', 365);
    }

    /** Nothing on sale is free — the check that would catch a free tier creeping back in. */
    public function test_no_offered_plan_is_free(): void
    {
        foreach ($this->catalogue()->offered() as $plan) {
            $this->assertGreaterThan(
                0,
                (float) $plan->price_monthly,
                "plan [{$plan->code}] is offered at no charge, which reopens the unpaid route into the product",
            );
        }
    }

    public function test_an_unknown_plan_is_not_quotable(): void
    {
        $this->getJson('/api/v1/plans/no-such-plan/quote')->assertStatus(404);
    }

    // ── Trial limits ──────────────────────────────────────────────────────────────────────────

    /**
     * A trial is a look at the product, not a slice of the plan's capacity.
     *
     * An absent trial limit means "on the plan's terms for that metric", not "unlimited" — the
     * opposite reading would hand a trial account everything the plan does not happen to cap.
     */
    public function test_trial_limits_narrow_the_plan_and_fall_back_to_it(): void
    {
        $growth = $this->catalogue()->byCode('growth');

        $this->assertSame(3, $growth->trialLimitFor('projects'), 'the trial caps projects tighter');
        $this->assertSame(25, $growth->limitFor('projects'), 'the plan itself is unchanged');

        // A metric the trial says nothing about follows the plan.
        SubscriptionPlan::where('code', 'growth')->update(['trial_limits' => ['projects' => 3]]);
        $growth = $this->catalogue()->byCode('growth');
        $this->assertSame(15, $growth->trialLimitFor('team_members'));
    }

    // ── Editing from the console ──────────────────────────────────────────────────────────────

    /** The trial's fee and length are a setting, not a deploy. */
    public function test_the_platform_owner_can_change_the_trial_terms(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->getKey()}", [
                'trial_fee' => 15, 'trial_days' => 14, 'price_annual' => 5990,
            ])->assertOk()
            ->assertJsonPath('data.plan.trial_days', 14);

        /*
         * And the PUBLIC quote moves with it — one statement, not two.
         *
         * Asked on the MONTHLY term. This used to ask the annual quote to reflect a change to the
         * introductory terms, which it can no longer do and should never have done: the annual term
         * is bought outright (PAY-AUDIT-003). The annual PRICE change is asserted separately below,
         * because that one does belong to the annual term.
         */
        $this->getJson('/api/v1/plans/growth/quote?interval=monthly')->assertOk()
            ->assertJsonPath('data.quote.due_now', '15.00')
            ->assertJsonPath('data.quote.due_later', (string) $plan->refresh()->price_monthly)
            ->assertJsonPath('data.quote.renews_in_days', 14);

        $this->getJson('/api/v1/plans/growth/quote?interval=annual')->assertOk()
            ->assertJsonPath('data.quote.due_now', '5990.00')
            ->assertJsonPath('data.quote.renews_in_days', 365);
    }

    /** A price change is a commercial decision, so it is audited like one. */
    public function test_changing_a_price_is_audited(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->actingAs($this->platformAdmin(), 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->getKey()}", ['price_monthly' => 599])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.plan.updated',
            'entity_id' => (string) $plan->getKey(),
        ]);
    }

    public function test_a_customer_cannot_edit_the_catalogue(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $customer = User::create(['name' => 'C', 'email' => 'c@plans.test', 'password' => 'secret1234']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->getKey()}", ['price_monthly' => 0])
            ->assertForbidden();

        $this->assertSame((string) $plan->price_monthly, (string) $plan->refresh()->price_monthly);
    }

    // ── Registration reads the same catalogue ─────────────────────────────────────────────────

    public function test_registration_accepts_a_plan_that_is_on_sale(): void
    {
        $this->apply(['email' => 'buyer@a.test', 'plan_code' => 'growth'])->assertStatus(202);

        $this->assertSame('growth', RegistrationRequest::query()->firstOrFail()->plan_code);
    }

    /**
     * …and refuses one that is not.
     *
     * "Which plans may somebody sign up for?" is answered from /admin, and a payload naming a
     * withdrawn or private plan must not be able to answer it instead.
     */
    public function test_registration_refuses_a_plan_that_is_not_on_sale(): void
    {
        SubscriptionPlan::where('code', 'growth')->update(['is_public' => false]);

        $this->apply(['email' => 'sneaky@a.test', 'plan_code' => 'growth'])
            ->assertStatus(422)->assertJsonValidationErrors(['plan_code']);

        $this->apply(['email' => 'sneaky@a.test', 'plan_code' => 'invented'])
            ->assertStatus(422)->assertJsonValidationErrors(['plan_code']);

        $this->assertSame(0, RegistrationRequest::query()->count());
    }
}
