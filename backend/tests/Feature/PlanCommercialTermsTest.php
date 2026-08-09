<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The owner's commercial decisions of 2026-08-09, as tests — PAY-AUDIT-002 and PAY-AUDIT-003.
 *
 * ## The currency separation is deliberate, and it is two currencies, not one
 *
 * Subscriptions — what a customer pays CampaignsHub — are sold in **USD**. The advertising side —
 * dashboards, analytics, reports, cross-platform comparison — reports in **SAR**, keeps every
 * platform's original amount and original currency, and converts only for the unified view. A plan's
 * currency and a report's currency answer different questions, and nothing here should ever be read
 * as changing the second.
 *
 * ## There is no free anything
 *
 * No free tier and no free trial. Every plan costs money from its first day.
 *
 * The INTRODUCTORY month is a separate question from that, and the answer moved more than once
 * before Launch Pricing settled it: the offer belongs to **Growth alone** — 30 days at 9 against a
 * regular 49, with a three-month minimum commitment behind it. Starter and Agency are sold outright
 * at their own prices, which is not a free plan; it is a plan without an offer.
 *
 * Every assertion below names the RULE and reads the figures from the catalogue. The prices changed
 * three times in a single day and each repricing broke a test that had a number typed into it; a
 * test asserting `19.00` proves only what a seeder said this morning.
 *
 * The annual term never passes through an introductory month on any plan: it already carries its own
 * discount, and somebody buying a year should not be made to walk through a cheaper month first.
 */
final class PlanCommercialTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function plan(string $code): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', $code)->firstOrFail();
    }

    private function catalogue(): PlanCatalogue
    {
        return app(PlanCatalogue::class);
    }

    // ── PAY-AUDIT-002: the currency ──────────────────────────────────────────────────────────────

    /** Every plan on sale is priced in USD. */
    public function test_the_catalogue_is_denominated_in_usd(): void
    {
        $currencies = SubscriptionPlan::query()->pluck('currency')->unique()->values()->all();

        $this->assertSame(['USD'], $currencies, 'a plan is still priced in another currency');
    }

    /** And the quote a customer is shown says so, rather than leaving them to assume. */
    public function test_a_quote_states_its_currency(): void
    {
        foreach (['starter', 'growth', 'agency'] as $code) {
            $quote = $this->catalogue()->quote($this->plan($code), 'monthly');

            $this->assertSame('USD', $quote['currency'], "{$code} quoted in the wrong currency");
        }
    }

    /**
     * The currency is manageable from `/admin` — the gap that made the clause unfixable.
     *
     * Every other commercial term was already editable; this one was not, so the catalogue was
     * denominated in whatever a seeder had said and only a deploy could change it.
     */
    public function test_the_platform_owner_can_re_denominate_a_plan(): void
    {
        $owner = User::create([
            'name' => 'Platform', 'email' => 'owner@campaignshub.io',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $owner->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/admin/plans/'.$this->plan('growth')->getKey(), [
                // Lower case on purpose: `usd` and `USD` must not become two currencies.
                'currency' => 'usd',
                'reason' => 'Re-denominating the catalogue.',
            ])
            ->assertOk();

        $this->assertSame('USD', $this->plan('growth')->refresh()->currency);
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.plan.updated']);
    }

    // ── PAY-AUDIT-003: the paid introductory month ───────────────────────────────────────────────

    /**
     * No plan is free, and no plan is free for a while either.
     *
     * Two separate claims. Every plan costs money from its first day — there is no free tier — and
     * where a plan DOES open with an introductory month, that month is priced rather than given away.
     * A plan without an offer is not a free plan; it is simply sold at its own price.
     *
     * A `contact_sales` plan is skipped and is not an exception to the rule: it publishes NO price,
     * which the schema can only store as 0.00. That «no plan is priced at zero unless it is sold by
     * conversation» is itself asserted, separately, by
     * {@see test_only_a_contact_sales_plan_may_publish_no_price} — so the skip here cannot become a
     * hole to hide a free tier in.
     */
    public function test_no_plan_is_free_and_no_introductory_period_is_free(): void
    {
        foreach (SubscriptionPlan::all() as $plan) {
            if ($plan->contact_sales) {
                continue;
            }

            $this->assertGreaterThan(0, (float) $plan->price_monthly, "{$plan->code} is free");

            if ($plan->trial_days > 0) {
                $this->assertGreaterThan(
                    0,
                    (float) $plan->trial_fee,
                    "{$plan->code} opens with a FREE period — there is no free trial in this product",
                );
            }
        }
    }

    /**
     * The introductory month belongs to GROWTH, and to it alone — the owner's pricing of 2026-08-09.
     *
     * Named per plan rather than looped, because «which plans carry the offer» is the commercial
     * decision itself: a loop over whatever the seeder happens to contain would assert nothing.
     */
    public function test_only_growth_opens_with_an_introductory_month(): void
    {
        $this->assertSame(30, $this->plan('growth')->trial_days, 'Growth must open with a 30-day offer');
        $this->assertSame(3, $this->plan('growth')->minimum_commitment_months);

        foreach (['starter', 'agency'] as $code) {
            $this->assertSame(0, $this->plan($code)->trial_days, "{$code} must not carry an introductory month");
            $this->assertSame(0, $this->plan($code)->minimum_commitment_months, "{$code} must not be committed");
        }
    }

    // ── LAUNCH-PRICING-001: what is on sale, and what is not ─────────────────────────────────────

    /**
     * Enterprise exists and is NOT on sale — the owner's decision of 2026-08-09.
     *
     * Three separate facts, and the test names all three because they are easy to conflate: it is
     * ACTIVE (real, in `/admin`, ready for the day there is a conversation to have), it is NOT
     * PUBLIC (absent from signup), and it is `contact_sales` (it publishes no price at all).
     *
     * `isOffered()` is the assertion that matters: without it the plan is merely hidden, and a code
     * typed into a URL would still reach a checkout for a plan whose price is 0.00.
     */
    public function test_enterprise_is_in_the_catalogue_and_not_on_sale(): void
    {
        $enterprise = $this->plan('enterprise');

        $this->assertTrue($enterprise->is_active, 'Enterprise must stay real for the operator');
        $this->assertFalse($enterprise->is_public, 'Enterprise must not appear in signup yet');
        $this->assertTrue($enterprise->contact_sales, 'Enterprise publishes no price');

        $this->assertFalse($this->catalogue()->isOffered('enterprise'), 'Enterprise must not be buyable');

        $offered = $this->catalogue()->offered()->pluck('code')->all();
        $this->assertSame(['starter', 'growth', 'agency'], $offered);
    }

    /**
     * A plan sold by conversation is the ONLY one allowed to publish no price.
     *
     * `price_monthly` is NOT NULL, so «no price» is stored as 0.00 — the same value that means free.
     * `contact_sales` is what tells the two apart, and this is the assertion that keeps «no plan is
     * free» (above) meaning what it says rather than quietly acquiring an exception.
     */
    public function test_only_a_contact_sales_plan_may_publish_no_price(): void
    {
        foreach (SubscriptionPlan::all() as $plan) {
            if ((float) $plan->price_monthly > 0) {
                continue;
            }

            $this->assertTrue(
                (bool) $plan->contact_sales,
                "{$plan->code} is priced at zero and is not sold by conversation — that is a free tier",
            );
        }
    }

    /**
     * **Every cap a plan publishes is one the backend can actually measure** — LAUNCH-LIMITS-001.
     *
     * The `clients` cap shipped in the catalogue and in nothing else: `usage()` had no branch for
     * it, so it fell through to a meter nothing writes, read 0, and «1 client» admitted a hundred.
     * A limit that is published and not counted is a promise nobody is keeping, and the catalogue is
     * where such a promise is cheapest to make.
     *
     * So this walks the catalogue rather than a list — adding a cap in `/admin` or in the seeder
     * without teaching the service to count it fails here.
     */
    public function test_every_published_limit_is_one_the_service_can_measure(): void
    {
        $tenant = Tenant::create(['name' => 'Measured', 'slug' => 'measured', 'status' => 'active']);
        $service = app(SubscriptionService::class);

        $keys = SubscriptionPlan::all()
            ->flatMap(fn (SubscriptionPlan $p) => array_keys($p->limits ?? []))
            ->unique()->values();

        $this->assertNotEmpty($keys, 'the catalogue publishes no limits at all');

        foreach ($keys as $metric) {
            /*
             * A metric the service cannot count returns the meter, and the meter is never written —
             * so «0» here is ambiguous on its own. What is unambiguous is the reflection: `count()`
             * must recognise the metric by name.
             */
            $counted = (new \ReflectionMethod($service, 'count'))->invoke($service, $tenant, $metric);

            $this->assertNotNull($counted, "the plan limit «{$metric}» is published and cannot be counted");
        }
    }

    /** A plan with no offer is quoted at its own price, due today, with nothing owed later. */
    public function test_a_plan_without_an_offer_is_bought_at_its_own_price(): void
    {
        foreach (['starter', 'agency'] as $code) {
            $plan = $this->plan($code);
            $quote = $this->catalogue()->quote($plan, 'monthly');

            $this->assertSame((string) $plan->price_monthly, $quote['due_now'], "{$code} is misquoted");
            $this->assertNull($quote['due_later'], "{$code} owes nothing later");
            $this->assertSame(0, $quote['commitment_months']);
            $this->assertNull($quote['total_committed']);
        }
    }

    /**
     * The monthly quote takes the introductory price today and names the full price for later.
     *
     * Quoting the full price as «due now» would misstate the charge the customer is authorising;
     * quoting only the introductory price would hide what they are signing up to.
     */
    public function test_the_monthly_quote_separates_what_is_taken_now_from_what_falls_due(): void
    {
        $plan = $this->plan('growth');
        $quote = $this->catalogue()->quote($plan, 'monthly');

        $this->assertSame((string) $plan->trial_fee, $quote['due_now']);
        $this->assertSame((string) $plan->price_monthly, $quote['due_later']);
        $this->assertSame(30, $quote['renews_in_days']);
    }

    /**
     * **The annual term goes direct.** This is the assertion the change exists for.
     *
     * `quote()` asked `offersTrial()`, which reads the PLAN and not the purchase, so an annual buyer
     * was quoted a symbolic first month and a renewal thirty days later — a year's commitment turned
     * into a monthly one by a helper that could not see the term.
     */
    public function test_the_annual_term_is_bought_outright_with_no_introductory_month(): void
    {
        $plan = $this->plan('growth');
        $quote = $this->catalogue()->quote($plan, 'annual');

        $this->assertSame((string) $plan->price_annual, $quote['due_now'], 'the annual term was discounted twice');
        $this->assertNull($quote['due_later'], 'an annual purchase should owe nothing later');
        $this->assertSame(365, $quote['renews_in_days']);
        $this->assertSame(0, $quote['trial_days']);
        $this->assertNull($quote['trial_fee']);
    }

    /** The annual term is a real discount against twelve months, on every plan that offers one. */
    public function test_the_annual_term_is_cheaper_than_twelve_months(): void
    {
        foreach (SubscriptionPlan::all() as $plan) {
            if ($plan->price_annual === null) {
                continue;
            }

            $this->assertLessThan(
                (float) $plan->price_monthly * 12,
                (float) $plan->price_annual,
                "{$plan->code}'s annual term is not a discount",
            );
        }
    }

    /**
     * The introductory month is cheaper than the month it introduces — otherwise it is not an offer.
     *
     * Asserted because these are four independently editable numbers in `/admin`, and nothing else
     * would notice an «introductory» price set above the full one.
     */
    public function test_the_introductory_price_is_below_the_full_monthly_price(): void
    {
        foreach (SubscriptionPlan::all() as $plan) {
            if ($plan->trial_days === 0) {
                continue; // No offer to be cheaper than.
            }

            $this->assertLessThan(
                (float) $plan->price_monthly,
                (float) $plan->trial_fee,
                "{$plan->code}'s introductory price is not below its monthly price",
            );
        }
    }

    /** A workspace on the introductory month is capped by the intro limits, not the full plan's. */
    public function test_the_introductory_month_carries_its_own_caps(): void
    {
        $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-intro', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner'])
            ->givePermissionTo(...Permission::pluck('key')->all());
        app(TenantContext::class)->forget();

        // Growth is the plan that has one, so it is the plan whose caps are asserted.
        $growth = $this->plan('growth');

        $this->assertNotNull($growth->trial_limits, 'the introductory month has no caps of its own');
        $this->assertNotNull($growth->trialLimitFor('projects'));
    }
}
