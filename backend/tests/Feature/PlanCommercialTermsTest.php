<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\PlanCatalogue;
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
 * Every plan opens with a PAID first month at an introductory price and then charges in full. Not a
 * free trial, not a free tier, and — since the annual term already carries its own discount — not on
 * the annual term either: somebody buying a year is not made to pass through a cheaper month first.
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
        foreach (['starter', 'growth', 'scale'] as $code) {
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

    /** No plan is free, and no plan is free for a while either. */
    public function test_no_plan_is_free_and_none_offers_a_free_period(): void
    {
        foreach (SubscriptionPlan::all() as $plan) {
            $this->assertGreaterThan(0, (float) $plan->price_monthly, "{$plan->code} is free");
            $this->assertGreaterThan(
                0,
                (float) $plan->trial_fee,
                "{$plan->code} opens with a FREE period — the decision was a paid introductory month",
            );
        }
    }

    /** Thirty days, on every plan — including the entry plan, which used to be excluded. */
    public function test_every_plan_opens_with_a_thirty_day_introductory_month(): void
    {
        foreach (['starter', 'growth', 'scale'] as $code) {
            $this->assertSame(30, $this->plan($code)->trial_days, "{$code} does not open with a month");
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

        $starter = $this->plan('starter');

        $this->assertNotNull($starter->trial_limits, 'the entry plan gained a month with no caps of its own');
        $this->assertSame(3, $starter->trialLimitFor('projects'));
    }
}
