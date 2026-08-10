<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\AppliesToRegister;
use Tests\Concerns\ConfirmsGatewayPayments;
use Tests\TestCase;

/**
 * SUB-COMMIT-001 / SUB-CONSENT-001 — what stands behind the introductory price.
 *
 * ## The hole this closes
 *
 * A paid first month at 9 with nothing behind it is an arbitrage, not an offer: subscribe, cancel on
 * day 29, repeat, and the product is 94% off forever. The commitment is what makes the discount a
 * commercial term — the cheap month is bought with an agreement to the two that follow.
 *
 * ## What a commitment is NOT
 *
 * It is not a refusal to let somebody leave, and these tests are written to hold that line as much as
 * the other one. The customer can always ASK to cancel; the request is recorded and honoured; nothing
 * is taken away that was paid for. What changes is only WHEN it takes effect — and afterwards the
 * subscription is an ordinary monthly one that stops at the next cycle like any other.
 */
final class MinimumCommitmentTest extends TestCase
{
    use AppliesToRegister;
    use ConfirmsGatewayPayments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();

        config([
            'accounts.registration.plans.growth' => ['requires_payment' => true],
            'services.moyasar.secret_key' => 'sk_test',
            'services.moyasar.webhook_token' => 'shared-secret',
        ]);
    }

    private function lifecycle(): SubscriptionLifecycle
    {
        return app(SubscriptionLifecycle::class);
    }

    /** An applicant who has agreed to the terms, walked to the point of paying. */
    private function applicant(string $email, string $interval = 'monthly', bool $agreed = true): RegistrationRequest
    {
        $res = $this->apply([
            'email' => $email, 'plan_code' => 'growth', 'billing_interval' => $interval,
            'phone' => '+96650'.random_int(1000000, 9999999),
            'tenant_name' => 'Committed '.$email,
        ]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
        $this->verifyMobileFor($request);

        if ($agreed) {
            $request->forceFill(['commitment_consent_at' => Carbon::now()])->save();
        }

        return $request->refresh();
    }

    /** Pay whatever the charge actually is — never a literal, which is how a repricing breaks a test. */
    private function settle(SubscriptionPayment $payment): void
    {
        // The gateway's own confirmation, faked — see `ConfirmsGatewayPayments`.
        $this->gatewayConfirms($payment);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$payment->getKey(), 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_'.$payment->getKey(), 'status' => 'paid',
                'amount' => (int) round((float) $payment->amount * 100), 'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', true);
    }

    private function subscribe(string $email, string $interval = 'monthly'): Subscription
    {
        $request = $this->applicant($email, $interval);
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $payment = SubscriptionPayment::query()->where('registration_request_id', $request->getKey())->firstOrFail();
        $this->settle($payment);

        return Subscription::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->refresh()->tenant_id)->firstOrFail();
    }

    // ── The terms, quoted before anybody pays ────────────────────────────────────────────────────

    /** The whole disclosure the contract asks for, from the public quote endpoint. */
    public function test_the_quote_states_the_commitment_and_what_it_totals(): void
    {
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $quote = $this->getJson('/api/v1/plans/growth/quote?interval=monthly')
            ->assertOk()->json('data.quote');

        $this->assertSame((string) $plan->trial_fee, $quote['due_now'], 'today’s payment');
        $this->assertSame((string) $plan->price_monthly, $quote['regular_monthly'], 'the regular price');
        $this->assertSame(3, $quote['commitment_months'], 'the minimum commitment');
        $this->assertSame(Carbon::now()->addDays($plan->trial_days)->toDateString(), $quote['next_payment_on']);

        // Intro + the remaining months at the full price — the figure nobody works out for themselves.
        $expected = number_format((float) $plan->trial_fee + ((float) $plan->price_monthly * 2), 2, '.', '');
        $this->assertSame($expected, $quote['total_committed']);
        $this->assertSame('USD', $quote['currency']);

        /*
         * «How many more times will this card be charged?» — the question the other five figures
         * describe without answering. Today's is excluded: it is stated on its own line and is the
         * one being authorised right now.
         */
        $this->assertSame(2, $quote['remaining_committed_payments']);
    }

    /** No commitment, nothing still to come inside one — the number is 0, not null or absent. */
    public function test_an_uncommitted_quote_has_no_remaining_committed_payments(): void
    {
        $quote = $this->getJson('/api/v1/plans/starter/quote?interval=monthly')->assertOk()->json('data.quote');

        $this->assertSame(0, $quote['remaining_committed_payments']);
    }

    /** The annual term is bought outright and carries no commitment to disclose. */
    public function test_the_annual_term_has_no_commitment(): void
    {
        $quote = $this->getJson('/api/v1/plans/growth/quote?interval=annual')->assertOk()->json('data.quote');

        $this->assertSame(0, $quote['commitment_months']);
        $this->assertNull($quote['total_committed']);
    }

    // ── The consent gate ─────────────────────────────────────────────────────────────────────────

    /**
     * **The gate.** A committed charge cannot be opened without the agreement.
     *
     * Refused before any money is asked for, like trial abuse is — taking the fee and then finding
     * the terms were never agreed means refunding money we should not have requested.
     */
    public function test_a_committed_charge_is_refused_until_the_terms_are_agreed(): void
    {
        $request = $this->applicant('unagreed@a.test', agreed: false);

        $this->expectException(RuntimeException::class);
        app(SubscriptionCheckout::class)->startRegistrationPayment($request);
    }

    /** And the consent is recorded with a time, so a dispute can be answered with a date. */
    public function test_agreeing_at_checkout_records_when(): void
    {
        $request = $this->applicant('agreed@a.test', agreed: false);

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])
            ->assertOk();

        $this->assertNotNull($request->refresh()->commitment_consent_at);
    }

    // ── The commitment itself ────────────────────────────────────────────────────────────────────

    /** Paying the introductory price fixes the commitment on the subscription, from that day. */
    public function test_a_settled_introductory_payment_fixes_the_commitment(): void
    {
        $subscription = $this->subscribe('committed@a.test');

        $this->assertSame('trialing', $subscription->status);
        $this->assertSame(
            Carbon::now()->addMonths(3)->toDateString(),
            $subscription->commitment_ends_at?->toDateString(),
        );
        $this->assertNotNull($subscription->commitment_consent_at, 'the agreement travelled with the payment');
    }

    /**
     * Editing the plan afterwards does not move a commitment somebody already agreed to.
     *
     * The same protection `unit_amount` has, and for the same reason: the catalogue governs what NEW
     * customers are offered, the subscription governs what this one agreed to.
     */
    public function test_shortening_the_offer_later_does_not_shorten_an_agreed_commitment(): void
    {
        $subscription = $this->subscribe('locked@a.test');
        $ends = $subscription->commitment_ends_at?->toDateString();

        SubscriptionPlan::where('code', 'growth')->update(['minimum_commitment_months' => 0]);

        $this->assertSame($ends, $subscription->refresh()->commitment_ends_at?->toDateString());
    }

    /**
     * **The one that matters.** Cancelling inside the commitment does not end it early.
     *
     * The request is recorded and the renewal still runs — that is what «the payments agreed inside
     * the commitment remain due» means when the sweep actually runs, rather than in a paragraph.
     */
    public function test_cancelling_inside_the_commitment_does_not_stop_the_agreed_renewals(): void
    {
        $subscription = $this->subscribe('leaver@a.test');

        // The introductory month ends and converts, exactly as it would unattended.
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->subDay()])->save();
        $this->lifecycle()->convertDueTrials(app(SubscriptionCheckout::class));
        $renewal = SubscriptionPayment::query()->where('subscription_id', $subscription->getKey())
            ->where('purpose', '!=', 'trial')->latest('id')->firstOrFail();
        $this->settle($renewal);

        // Month two. The customer asks to leave.
        $this->lifecycle()->cancel($subscription->refresh(), 'The customer asked to cancel.', atPeriodEnd: true);
        $this->assertTrue((bool) $subscription->refresh()->cancel_at_period_end, 'the request was not recorded');

        // The period ends while still inside the commitment: it renews rather than stopping.
        $subscription->forceFill(['status' => 'active', 'current_period_end' => Carbon::now()->subMinute()])->save();
        $this->lifecycle()->chargeDueRenewals(app(SubscriptionCheckout::class));

        $this->assertNotSame('canceled', $subscription->refresh()->status, 'the commitment was escaped');
        $this->assertTrue((bool) $subscription->cancel_at_period_end, 'the request must survive to be honoured later');
    }

    /**
     * …and it IS honoured, the moment the commitment is served. Nobody has to ask twice.
     *
     * Without this the first test would describe a trap rather than a term.
     */
    public function test_the_cancellation_takes_effect_once_the_commitment_is_served(): void
    {
        $subscription = $this->subscribe('served@a.test');

        $this->lifecycle()->cancel($subscription, 'The customer asked to cancel.', atPeriodEnd: true);

        $subscription->forceFill([
            'status' => 'active',
            'commitment_ends_at' => Carbon::now()->subDay(),
            'current_period_end' => Carbon::now()->subMinute(),
        ])->save();

        $this->lifecycle()->chargeDueRenewals(app(SubscriptionCheckout::class));

        $this->assertSame('canceled', $subscription->refresh()->status);
    }

    /** A subscription with no commitment stops at the end of its period, as it always did. */
    public function test_an_uncommitted_subscription_still_stops_at_the_period_end(): void
    {
        $subscription = $this->subscribe('free-to-go@a.test');

        $this->lifecycle()->cancel($subscription, 'The customer asked to cancel.', atPeriodEnd: true);
        $subscription->forceFill([
            'status' => 'active',
            'commitment_ends_at' => null,
            'current_period_end' => Carbon::now()->subMinute(),
        ])->save();

        $this->lifecycle()->chargeDueRenewals(app(SubscriptionCheckout::class));

        $this->assertSame('canceled', $subscription->refresh()->status);
    }

    // ── /admin owns the terms ────────────────────────────────────────────────────────────────────

    /** The commitment is a commercial term, editable beside the two prices it stands behind. */
    public function test_the_platform_owner_can_change_the_commitment(): void
    {
        $owner = User::create([
            'name' => 'Platform', 'email' => 'owner@campaignshub.io',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $owner->forceFill(['is_platform_admin' => true])->save();
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/v1/admin/plans/'.$plan->getKey(), [
                'minimum_commitment_months' => 6,
                'reason' => 'Lengthening the introductory commitment.',
            ])
            ->assertOk()
            ->assertJsonPath('data.plan.minimum_commitment_months', 6);

        // …and the PUBLIC quote moves with it — one catalogue, not two.
        $this->getJson('/api/v1/plans/growth/quote?interval=monthly')
            ->assertOk()->assertJsonPath('data.quote.commitment_months', 6);
    }
}
