<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\TrialClaim;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Subscriptions\Services\TrialEligibility;
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
 * The account that runs itself (PAY-003) and the trial that cannot be taken twice (PAY-004).
 *
 * Every case below is a thing that happens without anybody watching: a trial reaching its last day, a
 * card being refused, a grace period running out, a refund arriving weeks later. The point of testing
 * them is that nobody will be there when they do.
 */
final class SubscriptionLifecycleTest extends TestCase
{
    use AppliesToRegister;
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

    /** Walk an applicant all the way to a paid trial, through the real webhook. */
    private function paidTrial(string $email = 'trialist@a.test', string $interval = 'monthly'): Subscription
    {
        $res = $this->apply([
            'email' => $email, 'plan_code' => 'growth', 'billing_interval' => $interval,
            'phone' => '+96650'.random_int(1000000, 9999999),
            'tenant_name' => 'Trial Co '.$email,
        ]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

        $payment = SubscriptionPayment::query()->where('registration_request_id', $request->getKey())->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$payment->getKey(), 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_'.$payment->getKey(), 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk()->assertJsonPath('data.verified', true);

        return Subscription::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->refresh()->tenant_id)->firstOrFail();
    }

    // ── The paid trial ────────────────────────────────────────────────────────────────────────

    public function test_a_confirmed_trial_fee_starts_a_trial_with_the_plans_own_length(): void
    {
        $subscription = $this->paidTrial();

        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialing());
        // Compared as a DATE: `diffInDays` truncates, so the milliseconds spent walking the webhook
        // would make a seven-day trial measure six.
        $this->assertSame(
            Carbon::now()->addDays(7)->toDateString(),
            $subscription->trial_ends_at?->toDateString(),
        );
        // Consent to auto-conversion is recorded WHEN it was given, not merely that it was.
        $this->assertNotNull($subscription->auto_convert_consent_at);
    }

    /** A trial is capped tighter than the plan it is a trial of. */
    public function test_a_trial_is_capped_by_the_trial_limits_not_the_plans(): void
    {
        $subscription = $this->paidTrial();
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        $service = app(SubscriptionService::class);

        $this->assertSame(3, $service->effectiveLimit($tenant, 'projects'), 'the trial cap');
        $this->assertSame(25, $subscription->plan->limitFor('projects'), 'the plan is unchanged');

        // …and once the trial is over, the plan's own cap applies.
        $subscription->forceFill(['status' => 'active', 'trial_ends_at' => null])->save();
        $this->assertSame(25, $service->effectiveLimit($tenant, 'projects'));
    }

    // ── Conversion ────────────────────────────────────────────────────────────────────────────

    /**
     * A trial that ends converts — but into a CHARGE, not into an active subscription.
     *
     * The distinction is the whole of PAY-002 applied to renewals: the account does not become paid
     * because a date passed, it becomes paid when the gateway says so.
     */
    public function test_a_due_trial_opens_a_charge_and_does_not_activate_by_itself(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->subDay()])->save();

        $this->lifecycle()->convertDueTrials(app(SubscriptionCheckout::class));

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status, 'owed, not paid');
        $this->assertSame(
            1,
            SubscriptionPayment::query()->where('subscription_id', $subscription->getKey())->count(),
            'exactly one renewal charge',
        );
    }

    /**
     * A trial with no recorded consent is CANCELLED, not billed.
     *
     * The contract requires explicit consent to auto-conversion, and the only safe reading of a
     * missing consent is that the charge was never authorised.
     */
    public function test_a_trial_without_consent_is_cancelled_rather_than_charged(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill([
            'trial_ends_at' => Carbon::now()->subDay(),
            'auto_convert_consent_at' => null,
        ])->save();

        $this->lifecycle()->convertDueTrials(app(SubscriptionCheckout::class));

        $this->assertSame('canceled', $subscription->refresh()->status);
        $this->assertSame(0, SubscriptionPayment::query()->where('subscription_id', $subscription->getKey())->count());
    }

    /** An annual trial converts onto the annual term, not silently onto a month. */
    public function test_the_term_the_applicant_chose_is_what_the_trial_converts_into(): void
    {
        $subscription = $this->paidTrial('annual@a.test', 'annual');

        $this->assertSame('annual', $subscription->billing_interval);
        $this->assertSame('4990.00', (string) $subscription->unit_amount);
    }

    // ── Failure, grace and suspension ─────────────────────────────────────────────────────────

    public function test_a_refused_renewal_starts_a_grace_period_rather_than_ending_the_account(): void
    {
        $subscription = $this->paidTrial();
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        $this->lifecycle()->enterPastDue($subscription, 'The gateway refused the renewal.');

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertNotNull($subscription->grace_ends_at);
        // Past due is OPERATIONAL — a card that expired is not a customer who left.
        $this->assertSame(AccountState::PastDue, $tenant->refresh()->account_state);
        $this->assertContains(AccountState::PastDue, AccountState::operational());
    }

    /**
     * Grace that runs out suspends the account — and keeps every row it owns.
     *
     * «عدم حذف بيانات العميل عند التعليق». A suspension that deleted would make non-payment
     * unrecoverable, which is not a business model.
     */
    public function test_expired_grace_suspends_the_account_without_deleting_anything(): void
    {
        $subscription = $this->paidTrial();
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        $workspacesBefore = Workspace::query()
            ->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->getKey())->count();

        $this->lifecycle()->enterPastDue($subscription, 'refused');
        $subscription->forceFill(['grace_ends_at' => Carbon::now()->subDay()])->save();

        $this->assertSame(1, $this->lifecycle()->suspendAfterGrace());

        $this->assertSame('suspended', $subscription->refresh()->status);
        $this->assertSame(AccountState::Suspended, $tenant->refresh()->account_state);

        // The customer's work is untouched.
        $this->assertSame($workspacesBefore, Workspace::query()
            ->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->getKey())->count());
        $this->assertNotNull(Tenant::query()->withoutGlobalScopes()->find($tenant->getKey()));
    }

    public function test_a_suspended_account_can_be_reactivated(): void
    {
        $subscription = $this->paidTrial();
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);

        $this->lifecycle()->suspend($subscription, 'unpaid');
        $this->assertSame(AccountState::Suspended, $tenant->refresh()->account_state);

        $this->lifecycle()->reactivate($subscription->refresh(), 'A payment was confirmed.');

        $this->assertSame('active', $subscription->refresh()->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame(AccountState::Active, $tenant->refresh()->account_state);
    }

    /**
     * A refund weeks later does not leave the account running for free.
     *
     * Handled as a failed renewal rather than an instant cut-off: a chargeback is often somebody who
     * did not recognise a line on a statement.
     */
    public function test_a_refunded_renewal_puts_the_account_back_into_grace(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['status' => 'active'])->save();

        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'subscription_id' => $subscription->getKey(),
            'tenant_id' => $subscription->tenant_id,
            'purpose' => 'subscription', 'provider' => 'moyasar',
            'amount' => '499.00', 'currency' => 'SAR', 'status' => 'refunded',
            'idempotency_key' => 'refund-test',
        ])->save();

        $this->lifecycle()->paymentReversed($payment);

        $this->assertSame('past_due', $subscription->refresh()->status);
    }

    /** Cancelling at period end keeps the time already bought. */
    public function test_cancelling_at_period_end_does_not_take_away_paid_time(): void
    {
        $subscription = $this->paidTrial();

        $this->lifecycle()->cancel($subscription, 'The customer asked.', atPeriodEnd: true);

        $this->assertTrue($subscription->refresh()->cancel_at_period_end);
        $this->assertSame('trialing', $subscription->status, 'still running until the period ends');
        // …and a cancelled-at-period-end subscription never auto-converts.
        $this->assertFalse($subscription->mayAutoConvert());
    }

    // ── Trial abuse (PAY-004) ─────────────────────────────────────────────────────────────────

    public function test_a_paid_trial_claims_every_identity_it_can_establish(): void
    {
        $this->paidTrial('claimer@a.test');

        $kinds = TrialClaim::query()->pluck('kind')->sort()->values()->all();

        $this->assertSame(['company', 'email', 'phone'], $kinds);
        // Hashed, never stored in a form anybody could read back.
        $this->assertStringNotContainsString('claimer@a.test', (string) TrialClaim::query()->pluck('value_hash')->implode(','));
    }

    /** The same address, dressed up, is the same address. */
    public function test_dots_and_plus_tags_do_not_buy_a_second_trial(): void
    {
        $this->paidTrial('trialist@a.test');

        $second = new RegistrationRequest;
        $second->forceFill([
            'email' => 'tri.alist+again@a.test', 'name' => 'Again', 'tenant_name' => 'Different Co',
            'password' => 'x', 'state' => AccountState::EmailVerificationRequired->value,
        ])->save();

        $this->assertContains('email', app(TrialEligibility::class)->reasonsToRefuse($second->refresh()));
    }

    /** …and so is the same phone written a different way. */
    public function test_the_same_phone_in_another_format_does_not_buy_a_second_trial(): void
    {
        $request = RegistrationRequest::query()->firstOrNew([]);
        $request->forceFill([
            'email' => 'first@a.test', 'name' => 'First', 'tenant_name' => 'First Co',
            'phone' => '+966 50 111 2222', 'password' => 'x',
            'state' => AccountState::EmailVerificationRequired->value,
        ])->save();

        app(TrialEligibility::class)->claim($request->refresh());

        $second = new RegistrationRequest;
        $second->forceFill([
            'email' => 'second@a.test', 'name' => 'Second', 'tenant_name' => 'Second Co',
            'phone' => '0501112222', 'password' => 'x',
            'state' => AccountState::EmailVerificationRequired->value,
        ])->save();

        $this->assertSame(['phone'], app(TrialEligibility::class)->reasonsToRefuse($second->refresh()));
    }

    /** A refused trial never opens a charge — refusing beats refunding. */
    public function test_a_second_trial_is_refused_before_any_money_is_asked_for(): void
    {
        $this->paidTrial('trialist@a.test');

        $res = $this->apply([
            'email' => 'trialist2@a.test', 'plan_code' => 'growth',
            // The same company, which is the identity an abuser is least likely to change.
            'tenant_name' => 'Trial Co trialist@a.test',
        ]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', ['trialist2@a.test'])->firstOrFail();

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk()
            ->assertJsonPath('data.status', 'refused')
            ->assertJsonPath('data.refused', ['company']);

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertNull($request->tenant_id);
    }

    /** The sweep is safe to run twice — nothing double-charges and nothing double-suspends. */
    public function test_the_daily_sweep_is_safe_to_run_twice(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->subDay()])->save();

        $checkout = app(SubscriptionCheckout::class);
        $this->lifecycle()->runDueWork($checkout);
        $this->lifecycle()->runDueWork($checkout);

        $this->assertSame(
            1,
            SubscriptionPayment::query()->where('subscription_id', $subscription->getKey())->count(),
            'the second sweep must not open a second charge',
        );
    }

    // ── Over-limit behaviour (PLAN-003) ───────────────────────────────────────────────────────

    /**
     * A refusal that names the numbers, and takes nothing away.
     *
     * "You have reached your plan limit" leaves somebody to guess what the limit is, how close they
     * were, and whether upgrading would help. Saying 3 of 3 answers all three — and the contract asks
     * for the usage shown against the limit, not merely for the block.
     */
    public function test_hitting_a_plan_limit_is_refused_with_the_numbers_and_deletes_nothing(): void
    {
        $subscription = $this->paidTrial();
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($subscription->tenant_id);
        $user = User::where('email', 'trialist@a.test')->firstOrFail();

        // The trial caps projects at three. Meter it to the cap.
        $service = app(SubscriptionService::class);
        for ($i = 0; $i < 3; $i++) {
            $service->increment($tenant, 'projects');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/projects', ['name' => 'One too many'])
            ->assertForbidden();

        $response->assertJsonPath('meta.plan_limit', true)
            ->assertJsonPath('meta.metric', 'projects')
            ->assertJsonPath('meta.used', 3)
            ->assertJsonPath('meta.limit', 3)
            // Named, so the interface offers the upgrade rather than inventing a route.
            ->assertJsonPath('meta.upgrade_path', '/app/subscriptions');

        /*
         * The numbers, in the language the customer is being answered in (I18N-001).
         *
         * Asserted in BOTH, because the point of the message is the usage against the cap and a
         * translation is exactly where "3 of 3" quietly becomes "you have reached your limit". The
         * digits stay Latin in Arabic too — a cap in Eastern-Arabic numerals cannot be compared
         * against the list the customer is looking at.
         */
        $this->assertStringContainsString('3 من 3', (string) $response->json('message'));
        $this->assertStringContainsString('المشاريع', (string) $response->json('message'));

        $english = $this->actingAs($user, 'sanctum')
            ->withHeaders(['Accept-Language' => 'en'])
            ->postJson('/api/v1/projects', ['name' => 'One too many'])
            ->assertForbidden();

        $this->assertStringContainsString('3 of 3', (string) $english->json('message'));

        // Nothing the customer already had was removed or hidden — the create was refused, and that
        // is all that happened.
        $this->assertSame(3, $service->usage($tenant, 'projects'));
    }
}
