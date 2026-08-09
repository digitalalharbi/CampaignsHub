<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * PLAN-PAID-001 — «البداية» is bought, and buying it is what creates the workspace.
 *
 * The free tier is gone, so the interesting question is no longer "does registration work?" but
 * "what exists at each point before the money arrives?" — because the brief forbids an activated
 * workspace or an operational permission existing before a payment is actually verified.
 */
final class PaidRegistrationTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();
    }

    // ── Before the money ──────────────────────────────────────────────────────────────────────

    public function test_an_application_with_no_plan_is_refused(): void
    {
        $this->apply(['plan_code' => null, 'billing_interval' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code', 'billing_interval']);
    }

    /**
     * A verified email is not a workspace — and since PHONE-VERIFY-001 it is not even the next gate.
     *
     * The strongest form of the gate: everything the applicant can do on their own has been done, and
     * still nothing exists that they could sign in to.
     */
    public function test_a_verified_application_waits_at_the_payment_gate_with_nothing_created(): void
    {
        $applied = $this->apply(['email' => 'buyer@a.test', 'tenant_name' => 'Buyer Co'])->assertStatus(202);

        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk()->assertJsonPath('data.registration.provisioned', false);

        $registration = RegistrationRequest::query()->firstOrFail();

        // The email proved the address. It says nothing about the phone, so that is what is asked next.
        $this->assertSame(AccountState::MobileVerificationRequired, $registration->state);

        $this->verifyMobileFor($registration);
        $registration = $registration->refresh();
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $registration->state);

        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Membership::withoutGlobalScopes()->count());
        $this->assertSame(0, Subscription::withoutGlobalScope(TenantScope::class)->count());
    }

    /** Opening a checkout is not paying for one. */
    public function test_opening_a_checkout_activates_nothing(): void
    {
        $registration = $this->verifiedApplication();

        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);
        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $this->assertSame('pending', SubscriptionPayment::query()->firstOrFail()->status);
        $this->assertFalse($registration->refresh()->isProvisioned());
        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
    }

    /**
     * An UNSIGNED webhook is not a payment.
     *
     * The event is still recorded — a stream of them is what an attack looks like — and it moves
     * nothing.
     */
    public function test_an_unverified_webhook_cannot_activate_an_account(): void
    {
        $registration = $this->verifiedApplication();

        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);
        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_forged', 'type' => 'payment_paid',
            'secret_token' => 'not-the-shared-secret',
            'data' => [
                'id' => 'pay_forged', 'status' => 'paid', 'amount' => 9900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', false);

        $this->assertSame('pending', $payment->refresh()->status);
        $this->assertFalse($registration->refresh()->isProvisioned());
        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
    }

    /** A verified event for LESS than the charge settles nothing either. */
    public function test_a_short_payment_does_not_activate_an_account(): void
    {
        $registration = $this->verifiedApplication();

        config(['services.moyasar.secret_key' => 'sk_test', 'services.moyasar.webhook_token' => 'shared-secret']);
        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $payment = SubscriptionPayment::query()->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_short', 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_short', 'status' => 'paid',
                'amount' => 100, // one riyal against a 99-riyal charge
                'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk();

        $this->assertSame('failed', $payment->refresh()->status);
        $this->assertFalse($registration->refresh()->isProvisioned());
        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
    }

    // ── After the money ───────────────────────────────────────────────────────────────────────

    /**
     * The whole chain the brief names, in order: account → subscription → workspace → first project
     * → membership → role → the plan's permissions → the portal it lands in.
     */
    public function test_a_confirmed_payment_runs_the_whole_provisioning_chain(): void
    {
        ['user' => $user, 'registration' => $registration] = $this->applyAndVerify([
            'email' => 'buyer@a.test', 'tenant_name' => 'Buyer Co', 'account_type' => 'brand',
        ]);

        $this->assertTrue($registration->isProvisioned());
        $this->assertSame(AccountState::Active, $registration->state);

        $tenant = Tenant::withoutGlobalScopes()->findOrFail($registration->tenant_id);
        $this->assertTrue($tenant->isOperational());
        $this->assertNotNull($tenant->activated_at);

        // A membership, in the portal the account type asks for, carrying a real role.
        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->getKey())->firstOrFail();
        $this->assertSame('app', $membership->portal->value);
        $this->assertSame((string) $tenant->getKey(), (string) $membership->tenant_id);

        /*
         * The subscription the money bought — on the term that was chosen, opening with the paid
         * introductory month (PAY-AUDIT-003).
         *
         * This claim has been true, then false, then true again inside one day, which is worth
         * recording: «البداية» was sold outright, briefly gained an introductory month when every
         * plan did, and lost it again when the owner's marketing pricing put the offer on Growth
         * alone. The assertion follows the decision rather than the other way round.
         *
         * `unit_amount` is the FULL monthly price, not the introductory one: the subscription records
         * what this customer owes each period, and the introductory charge is a payment against the
         * first of them rather than a re-pricing of the plan.
         */
        $subscription = Subscription::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())->firstOrFail();
        $plan = SubscriptionPlan::where('code', 'starter')->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->trial_ends_at, 'a plan bought outright has no introductory window');
        $this->assertSame('monthly', $subscription->billing_interval);
        $this->assertSame((string) $plan->price_monthly, (string) $subscription->unit_amount);

        // A first project exists, so the workspace is not an empty room.
        $this->assertDatabaseHas('projects', ['tenant_id' => $tenant->getKey(), 'status' => 'setup']);

        // …and the owner can actually operate.
        $me = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        $me->assertJsonFragment(['role_slug' => 'tenant-owner']);
        $this->assertContains('reports', $me->json('data.user.account.nav'));
    }

    /** The annual term charges the annual amount, and says so before anybody pays. */
    public function test_the_annual_term_charges_the_annual_price(): void
    {
        $annual = (string) SubscriptionPlan::where('code', 'starter')->firstOrFail()->price_annual;

        $this->getJson('/api/v1/plans/starter/quote?interval=annual')->assertOk()
            ->assertJsonPath('data.quote.due_now', $annual)
            // Bought outright: the annual term does not pass through the introductory month.
            ->assertJsonPath('data.quote.due_later', null);

        ['registration' => $registration] = $this->applyAndVerify([
            'email' => 'annual@a.test', 'tenant_name' => 'Annual Co', 'billing_interval' => 'annual',
        ]);

        $payment = SubscriptionPayment::query()->where('registration_request_id', $registration->getKey())->firstOrFail();
        $this->assertSame($annual, (string) $payment->amount);

        $subscription = Subscription::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $registration->tenant_id)->firstOrFail();
        $this->assertSame('annual', $subscription->billing_interval);
        $this->assertTrue($subscription->current_period_end?->greaterThan(now()->addDays(360)) ?? false);
    }

    /** A gateway that retries must not charge, provision or subscribe twice. */
    public function test_a_redelivered_webhook_changes_nothing(): void
    {
        ['registration' => $registration] = $this->applyAndVerify(['email' => 'twice@a.test']);

        $payment = SubscriptionPayment::query()->firstOrFail();
        $before = Tenant::withoutGlobalScopes()->count();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$payment->getKey(), 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_'.$payment->getKey(), 'status' => 'paid', 'amount' => 9900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk();

        $this->assertSame($before, Tenant::withoutGlobalScopes()->count());
        $this->assertSame(1, SubscriptionPayment::query()->count());
        $this->assertSame(1, Subscription::withoutGlobalScope(TenantScope::class)->count());
    }

    /**
     * With no gateway credentials the checkout says `awaiting_credentials` — and no more.
     *
     * Not "failed", which would suggest a refusal, and not "pending", which would suggest somebody is
     * on their way to pay. Nobody is paying anything, and nothing is activated.
     */
    public function test_with_no_gateway_the_checkout_is_honest_and_activates_nothing(): void
    {
        config([
            'services.moyasar.secret_key' => null,
            'services.moyasar.webhook_token' => null,
            'services.stripe.secret' => null,
        ]);

        $registration = $this->verifiedApplication();

        $res = $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $this->assertSame('awaiting_credentials', $res->json('data.status'));
        $this->assertNull($res->json('data.checkout_url'));
        $this->assertSame('awaiting_credentials', SubscriptionPayment::query()->firstOrFail()->status);

        $this->assertFalse($registration->refresh()->isProvisioned());
        $this->assertSame(0, Tenant::withoutGlobalScopes()->count());
    }

    /**
     * Apply and clear every gate EXCEPT the money — an application waiting on payment alone.
     *
     * The mobile gate is walked here rather than skipped, because since PHONE-VERIFY-001 it comes
     * first: an application that has not proved its number never reaches the payment step, and a
     * helper that pretended otherwise would set these tests up against a state the product cannot
     * actually be in.
     */
    private function verifiedApplication(): RegistrationRequest
    {
        $applied = $this->apply(['email' => 'buyer@a.test', 'tenant_name' => 'Buyer Co'])->assertStatus(202);

        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->firstOrFail();
        $this->verifyMobileFor($registration);

        return $registration->refresh();
    }
}
