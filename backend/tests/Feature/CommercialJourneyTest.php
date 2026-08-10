<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * The whole commercial journey, in one run (JOURNEY-001).
 *
 * registration → plan → verification → approval → checkout → webhook → 7-day trial → conversion →
 * invoice and notification → failed renewal → grace → suspension → payment → reactivation.
 *
 * Every step is taken through the real path — the public endpoints, the real webhook verifier, the
 * real lifecycle sweep — because the individual units all pass in isolation and what this asserts is
 * that they connect. The interesting failures in a system like this live in the joins.
 *
 * **This test proves the CODE, not that money has moved.** It runs against a sandbox key with a
 * sandbox webhook secret. No credentials for a real gateway exist on this install, and nothing here
 * should be read as evidence that a live charge has ever succeeded.
 */
final class CommercialJourneyTest extends TestCase
{
    /**
     * The introductory fee plus 15% VAT, computed from the plan.
     *
     * Written as `10.35` while the fee was 9.00; the owner's marketing pricing made it 8.99, and
     * 8.99 × 1.15 rounds to 10.34. A money literal in a test is a price somebody has to remember to
     * edit — and this is the third time in one day that a commercial decision broke one.
     */
    private function introPlusVat(string $code = 'growth'): string
    {
        $fee = (float) SubscriptionPlan::query()->where('code', $code)->firstOrFail()->trial_fee;

        return number_format(round($fee * 1.15, 2), 2, '.', '');
    }

    use AppliesToRegister;
    use RefreshDatabase;

    private const EMAIL = 'journey@a.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();

        config([
            // The full gate: this plan is reviewed AND paid for.
            'accounts.registration.plans.growth' => ['requires_approval' => true, 'requires_payment' => true],
            // Sandbox credentials. Real ones do not exist here — see the class note.
            'services.moyasar.secret_key' => 'sk_test_journey',
            'services.moyasar.webhook_token' => 'sandbox-webhook-secret',
            'mail.default' => 'array',
        ]);
    }

    private function reviewer(): User
    {
        $user = User::create(['name' => 'Reviewer', 'email' => 'reviewer@a.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** A verified webhook, as the gateway would send it. */
    private function webhook(SubscriptionPayment $payment, string $status, int $amountInHalalas): void
    {
        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$status.'_'.$payment->getKey(),
            'type' => 'payment_'.$status,
            'secret_token' => 'sandbox-webhook-secret',
            'data' => [
                'id' => 'pay_'.$payment->getKey(), 'status' => $status,
                /*
                 * The gateway echoes back the currency it was ASKED to charge, so this walk must
                 * too. It said `SAR` against a subscription sold in USD (PAY-AUDIT-002) and passed,
                 * because until PAY-VERIFY-001 only the figure was compared — the test was
                 * depending on the gap it now proves is closed.
                 */
                'amount' => $amountInHalalas, 'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', true);
    }

    private function events(): array
    {
        return SubscriptionNotification::query()->pluck('event')->all();
    }

    public function test_the_whole_journey_from_signing_up_to_being_reactivated(): void
    {
        // ── 1. Registration, with a plan and a term ───────────────────────────────────────────
        $applied = $this->apply([
            'email' => self::EMAIL, 'tenant_name' => 'Journey Co',
            'plan_code' => 'growth', 'billing_interval' => 'monthly',
            'requested_portal' => 'agency', 'account_type' => 'agency',
        ])->assertStatus(202);

        // Nothing has been granted. This is the claim the whole gated path exists for.
        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, User::count());
        $applied->assertJsonPath('data.policy.requires_payment', true);

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [self::EMAIL])->firstOrFail();

        /*
         * ── 2. Email verification → held at the MOBILE gate, then the review one ───────────────
         *
         * The order is the policy's, not this test's: since PHONE-VERIFY-001 a proven address is
         * followed by a proven phone, and only then does a human look at the application.
         */
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk()->assertJsonPath('data.registration.state', 'mobile_verification_required');

        $this->verifyMobileFor($request);
        $this->getJson("/api/v1/auth/registration/{$request->getKey()}")
            ->assertJsonPath('data.registration.state', 'pending_approval');

        // Waiting on us, so the applicant is offered nothing to do.
        $this->getJson("/api/v1/auth/registration/{$request->getKey()}")
            ->assertJsonPath('data.registration.next_step', null);
        $this->assertSame(0, Tenant::count());

        // ── 3. Approval — which clears the REVIEW gate and nothing else ───────────────────────
        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve", ['note' => 'Checks out.'])
            ->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->assertSame(0, Tenant::count(), 'approving is not activating');
        $this->assertContains('registration_approved', $this->events());

        // ── 4. Checkout — a charge and an invoice, and still no account ───────────────────────
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $trialCharge = SubscriptionPayment::query()->where('purpose', 'trial')->firstOrFail();
        $trialInvoice = SubscriptionInvoice::query()->firstOrFail();

        $this->assertSame(
            (string) SubscriptionPlan::query()->where('code', 'growth')->firstOrFail()->trial_fee,
            (string) $trialCharge->amount,
        );
        $this->assertSame('issued', $trialInvoice->status, 'the document exists before the money does');
        $this->assertSame($this->introPlusVat(), $trialInvoice->outstanding(), 'the trial fee plus VAT');
        $this->assertSame(0, Tenant::count());

        // A repeat press of "pay" resolves to the SAME charge — «منع تكرار الخصم».
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();
        $this->assertSame(1, SubscriptionPayment::query()->count());
        $this->assertSame(1, SubscriptionInvoice::query()->count());

        // ── 5. The verified webhook — the ONLY thing that activates anything ──────────────────
        $this->webhook($trialCharge, 'paid', (int) round((float) $trialCharge->amount * 100));

        $request->refresh();
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertSame(1, Membership::count());
        $this->assertSame('paid', $trialInvoice->refresh()->status);
        $this->assertSame('0.00', $trialInvoice->outstanding());
        // …and the invoice is now attached to the workspace that did not exist when it was issued.
        $this->assertSame($request->tenant_id, $trialInvoice->tenant_id);

        // ── 6. The paid introductory month ────────────────────────────────────────────────────
        $subscription = Subscription::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->tenant_id)->firstOrFail();

        $this->assertSame('trialing', $subscription->status);
        // Read from the plan, not from a literal: the introductory period's length is an editable
        // commercial term (PAY-AUDIT-003 moved it from seven days to thirty).
        $introDays = SubscriptionPlan::where('code', 'growth')->firstOrFail()->trial_days;
        $this->assertSame(
            Carbon::now()->addDays($introDays)->toDateString(),
            $subscription->trial_ends_at?->toDateString(),
        );
        $this->assertNotNull($subscription->auto_convert_consent_at, 'consent is recorded, with a time');
        $this->assertContains('trial_started', $this->events());

        // The trial is capped tighter than the plan it is a trial of.
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);
        $this->assertSame(3, app(SubscriptionService::class)
            ->effectiveLimit($tenant, 'projects'));

        // ── 7. Warned before the charge, then converted ───────────────────────────────────────
        $lifecycle = app(SubscriptionLifecycle::class);
        $checkout = app(SubscriptionCheckout::class);

        $subscription->forceFill(['trial_ends_at' => Carbon::now()->addDay()])->save();
        $this->assertSame(1, $lifecycle->warnEndingTrials());
        $this->assertContains('trial_ending', $this->events());

        $subscription->refresh()->forceFill(['trial_ends_at' => Carbon::now()->subMinute()])->save();
        $lifecycle->convertDueTrials($checkout);

        // Converted into a CHARGE, not into an active subscription: the account becomes paid when
        // the gateway says so, not because a date passed.
        $this->assertSame('past_due', $subscription->refresh()->status);
        $renewal = SubscriptionPayment::query()->where('purpose', 'subscription')->firstOrFail();
        // The full monthly price, read from the plan — the amount is a commercial term, not a constant.
        $this->assertSame(
            (string) SubscriptionPlan::where('code', 'growth')->firstOrFail()->price_monthly,
            (string) $renewal->amount,
        );
        $this->assertContains('trial_converted', $this->events());

        // ── 8. The renewal invoice ────────────────────────────────────────────────────────────
        $renewalInvoice = SubscriptionInvoice::query()->where('subscription_payment_id', $renewal->getKey())->firstOrFail();
        $this->assertSame('issued', $renewalInvoice->status);
        // The full monthly price plus 15% VAT, computed from the plan rather than written down: the
        // price is an editable commercial term and the rate belongs to `TaxTreatment`.
        $monthly = (float) SubscriptionPlan::where('code', 'growth')->firstOrFail()->price_monthly;
        $this->assertSame(number_format($monthly * 1.15, 2, '.', ''), (string) $renewalInvoice->total);

        // ── 9. The renewal FAILS ──────────────────────────────────────────────────────────────
        $this->webhook($renewal, 'failed', (int) round((float) $renewal->amount * 100));

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at, 'a grace period is stamped on the row');
        $this->assertContains('renewal_failed', $this->events());
        $this->assertContains('past_due', $this->events());

        // Past due is OPERATIONAL — a card that expired is not a customer who left.
        $this->assertSame(AccountState::PastDue, $tenant->refresh()->account_state);
        $this->assertContains(AccountState::PastDue, AccountState::operational());

        // ── 10. Grace runs out → suspension, with every row kept ──────────────────────────────
        $workspacesBefore = Workspace::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())->count();

        $subscription->forceFill(['grace_ends_at' => Carbon::now()->subDay()])->save();
        $this->assertSame(1, $lifecycle->suspendAfterGrace());

        $this->assertSame('suspended', $subscription->refresh()->status);
        $this->assertSame(AccountState::Suspended, $tenant->refresh()->account_state);
        $this->assertContains('suspended', $this->events());

        // «عدم حذف بيانات العميل عند التعليق» — nothing was deleted.
        $this->assertSame($workspacesBefore, Workspace::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->getKey())->count());
        $this->assertSame(2, SubscriptionInvoice::query()->count(), 'the documents survive suspension');

        // ── 11. They pay → reactivation ───────────────────────────────────────────────────────
        $recovery = $checkout->chargeSubscription($subscription->refresh(), 'reactivation')['payment'];
        // The charge's own amount, in minor units — a literal here breaks the moment a price moves.
        $this->webhook($recovery, 'paid', (int) round((float) $recovery->amount * 100));

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_ends_at, 'the clock is cleared');
        $this->assertSame(AccountState::Active, $tenant->refresh()->account_state);
        $this->assertContains('payment_confirmed', $this->events());

        // ── The whole story, told ─────────────────────────────────────────────────────────────
        foreach ([
            'registration_approved', 'trial_started', 'trial_ending', 'trial_converted',
            'renewal_failed', 'past_due', 'suspended', 'payment_confirmed',
        ] as $event) {
            $this->assertContains($event, $this->events(), "the customer was never told about: {$event}");
        }

        // Three invoices for three charges, and the customer can read all of them.
        $owner = User::where('email', self::EMAIL)->firstOrFail();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/subscriptions/invoices')
            ->assertOk()->assertJsonCount(3, 'data.invoices');
    }

    /**
     * Everything above ran on a SANDBOX key, and the system says so.
     *
     * The contract is explicit that an internal test is not evidence of a real charge. This asserts
     * the product's own reporting agrees: the gateway is reachable-in-principle but reports its
     * environment as sandbox, and with the keys removed it reports awaiting_credentials rather than
     * pretending to be live.
     */
    public function test_the_journey_above_is_sandbox_and_the_system_reports_it_as_sandbox(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'ops@a.test', 'password' => 'secret1234']);
        $owner->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $settings = $this->actingAs($owner->refresh(), 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments')->assertOk()->json('data');

        $moyasar = collect($settings['providers'])->firstWhere('provider', 'moyasar');

        // Configured — but with a TEST key, and the console reads that from the key itself.
        $this->assertSame('live', $moyasar['status'], 'credentials are present');
        $this->assertSame('sandbox', $moyasar['environment'], 'and they are sandbox credentials');

        // And the notifications above went nowhere: a local transport is `sandbox`, never `sent`.
        $this->assertSame('sandbox', $settings['mail']['state']);

        // Remove them and nothing pretends otherwise.
        config(['services.moyasar.secret_key' => null, 'services.moyasar.webhook_token' => null]);
        $after = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/admin/settings/integrations/payments')->json('data');
        $this->assertSame(
            'awaiting_credentials',
            collect($after['providers'])->firstWhere('provider', 'moyasar')['status'],
        );
    }
}
