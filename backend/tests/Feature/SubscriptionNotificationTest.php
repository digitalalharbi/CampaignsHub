<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Subscriptions\Jobs\SendSubscriptionNotification;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Notifications\SubscriptionNotificationTemplates;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Scopes\TenantScope;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * Telling people what happened to their account and their money (NOTIF-SUB-001).
 *
 * The claim under test is not "a notification row was written". It is that the ledger says what
 * ACTUALLY happened — and specifically that `awaiting_credentials`, `sandbox` and `sent` stay apart,
 * because all three look like success from the caller's side and only one means a person received
 * anything.
 */
final class SubscriptionNotificationTest extends TestCase
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

    /** Walk an applicant to a paid trial through the real webhook, as the product does. */
    private function paidTrial(string $email = 'notified@a.test'): Subscription
    {
        $res = $this->apply([
            'email' => $email, 'plan_code' => 'growth', 'billing_interval' => 'monthly',
            'tenant_name' => 'Notified Co '.$email,
        ]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();

        // The mobile gate, cleared the way an applicant clears it (PHONE-VERIFY-001).

        $this->verifyMobileFor($request);

        $this->postJson("/api/v1/auth/registration/{$request->getKey()}/checkout")->assertOk();

        $payment = SubscriptionPayment::query()->where('registration_request_id', $request->getKey())->firstOrFail();

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$payment->getKey(), 'type' => 'payment_paid', 'secret_token' => 'shared-secret',
            'data' => ['id' => 'pay_1', 'status' => 'paid', 'amount' => 900, 'currency' => 'SAR',
                'metadata' => ['reference' => $payment->idempotency_key]],
        ])->assertOk();

        return Subscription::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $request->refresh()->tenant_id)->firstOrFail();
    }

    private function events(): array
    {
        return SubscriptionNotification::query()->pluck('event')->all();
    }

    // ── The three states ──────────────────────────────────────────────────────────────────────

    /**
     * With no mail transport at all, nothing claims to have been sent.
     *
     * `mail.default` pointing at a mailer that is not configured is the shipped state of an install
     * with no credentials, and the ledger has to say so rather than counting it as a delivery.
     */
    public function test_with_no_transport_the_message_is_awaiting_credentials(): void
    {
        // A real driver with no credentials — which is what an untouched install actually looks
        // like, and the case a naive "is the driver named?" check gets wrong.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.mailers.smtp.username' => '']);

        $this->paidTrial();

        $notification = SubscriptionNotification::query()->where('event', 'trial_started')->firstOrFail();
        (new SendSubscriptionNotification((string) $notification->getKey()))->handle();

        $notification->refresh();
        $this->assertSame('awaiting_credentials', $notification->status);
        $this->assertNull($notification->sent_at, 'nothing was sent, so there is no time it was sent at');
        $this->assertFalse($notification->reachedSomebody());
    }

    /**
     * A LOCAL transport writes the message and is recorded as `sandbox`, never as `sent`.
     *
     * This is the distinction that is easiest to lose: the mail facade succeeded, the job did not
     * throw, and everything looks fine — but no human being received anything.
     */
    public function test_a_local_transport_is_sandbox_and_not_sent(): void
    {
        config(['mail.default' => 'array']);
        Mail::fake();

        $this->paidTrial();

        $notification = SubscriptionNotification::query()->where('event', 'trial_started')->firstOrFail();
        (new SendSubscriptionNotification((string) $notification->getKey()))->handle();

        $notification->refresh();
        $this->assertSame('sandbox', $notification->status);
        $this->assertFalse($notification->reachedSomebody(), 'sandbox must never count as reaching somebody');
        $this->assertNotNull($notification->sent_at, 'something did happen, and when it happened is recorded');
    }

    /** Only a real transport is `sent`. */
    public function test_a_real_transport_is_the_only_thing_recorded_as_sent(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.provider.test',
            'mail.mailers.smtp.username' => 'campaignshub',
        ]);
        Mail::fake();

        $this->paidTrial();

        $notification = SubscriptionNotification::query()->where('event', 'trial_started')->firstOrFail();
        (new SendSubscriptionNotification((string) $notification->getKey()))->handle();

        $this->assertSame('sent', $notification->refresh()->status);
        $this->assertTrue($notification->reachedSomebody());
    }

    // ── The eight lifecycle moments ───────────────────────────────────────────────────────────

    public function test_a_confirmed_trial_fee_tells_the_customer_what_they_bought(): void
    {
        $this->paidTrial();

        $notification = SubscriptionNotification::query()->where('event', 'trial_started')->firstOrFail();

        /*
         * The message names the amount, the length and the date — "your subscription needs
         * attention" is not something anybody can act on.
         *
         * All three read from the PLAN: the length, the price and the currency are editable
         * commercial terms, and PAY-AUDIT-002/003 moved every one of them at once.
         */
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();

        $this->assertStringContainsString((string) $plan->trial_days, $notification->subject);
        $this->assertStringContainsString((string) $plan->trial_fee.' '.$plan->currency, $notification->body);
        $this->assertStringContainsString(
            Carbon::now()->addDays($plan->trial_days)->toDateString(),
            $notification->body,
        );
    }

    /**
     * The warning arrives BEFORE the charge, which is the whole of its value.
     *
     * A "your trial is ending" message that lands after the money has gone is not a warning.
     */
    public function test_an_ending_trial_is_warned_while_cancelling_is_still_free(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->addDay()])->save();

        $this->assertSame(1, $this->lifecycle()->warnEndingTrials());

        $warning = SubscriptionNotification::query()->where('event', 'trial_ending')->firstOrFail();
        // The full price that is about to be charged, in the plan's own currency.
        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $this->assertStringContainsString((string) $plan->price_monthly.' '.$plan->currency, $warning->body);

        // Still trialing — the warning did not convert anything.
        $this->assertSame('trialing', $subscription->refresh()->status);
    }

    /** A trial that will NOT convert is not warned about a charge that is not coming. */
    public function test_a_trial_with_no_consent_is_not_warned_about_a_charge(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill([
            'trial_ends_at' => Carbon::now()->addDay(),
            'auto_convert_consent_at' => null,
        ])->save();

        $this->assertSame(0, $this->lifecycle()->warnEndingTrials());
        $this->assertNotContains('trial_ending', $this->events());
    }

    public function test_the_whole_failure_path_is_narrated(): void
    {
        $subscription = $this->paidTrial();

        $this->lifecycle()->enterPastDue($subscription, 'The gateway refused the renewal.');
        $this->assertContains('past_due', $this->events());

        $subscription->refresh()->forceFill(['grace_ends_at' => Carbon::now()->subDay()])->save();
        $this->lifecycle()->suspendAfterGrace();
        $this->assertContains('suspended', $this->events());

        // The suspension message says explicitly that nothing was deleted — the first question
        // anybody locked out of their account asks.
        $suspended = SubscriptionNotification::query()->where('event', 'suspended')->firstOrFail();
        $this->assertStringContainsString('محفوظة', $suspended->body);

        $this->lifecycle()->reactivate($subscription->refresh(), 'A payment was confirmed.');
        $this->assertContains('reactivated', $this->events());
    }

    /** A confirmed renewal is acknowledged, so a customer knows the money arrived. */
    public function test_a_confirmed_renewal_is_acknowledged(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['status' => 'active'])->save();

        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'subscription_id' => $subscription->getKey(), 'tenant_id' => $subscription->tenant_id,
            'purpose' => 'subscription', 'provider' => 'moyasar',
            'amount' => '499.00', 'currency' => 'SAR', 'status' => 'paid',
            'idempotency_key' => 'renewal-ack',
        ])->save();

        $this->lifecycle()->renewalPaid($payment->refresh());

        $this->assertContains('payment_confirmed', $this->events());
    }

    // ── Dedup ─────────────────────────────────────────────────────────────────────────────────

    /**
     * The sweep is safe to run twice, so the messages must be too.
     *
     * Without dedup on the occasion, a customer whose card was refused receives "your card was
     * refused" every morning until they fix it — which is how a correct system becomes an
     * unbearable one.
     */
    public function test_running_the_sweep_twice_does_not_send_the_same_message_twice(): void
    {
        $subscription = $this->paidTrial();
        $subscription->forceFill(['trial_ends_at' => Carbon::now()->addDay()])->save();

        $checkout = app(SubscriptionCheckout::class);
        $this->lifecycle()->runDueWork($checkout);
        $this->lifecycle()->runDueWork($checkout);

        $this->assertSame(
            1,
            SubscriptionNotification::query()->where('event', 'trial_ending')->count(),
            'one warning per trial, not one per sweep',
        );
    }

    /** …but a LATER occasion of the same event is still delivered. */
    public function test_a_new_occasion_of_the_same_event_is_still_delivered(): void
    {
        $subscription = $this->paidTrial();

        $this->lifecycle()->enterPastDue($subscription, 'first');
        $first = SubscriptionNotification::query()->where('event', 'past_due')->count();

        // A new grace period is a new occasion — next month's failure must reach the customer.
        $subscription->refresh()->forceFill(['grace_ends_at' => Carbon::now()->addMonth()])->save();
        $this->lifecycle()->enterPastDue($subscription->refresh(), 'second');

        $this->assertSame(1, $first);
        $this->assertSame(2, SubscriptionNotification::query()->where('event', 'past_due')->count());
    }

    // ── Applicants, who have no tenant and no bell ────────────────────────────────────────────

    public function test_an_applicant_is_told_when_their_registration_is_decided(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $res = $this->apply(['email' => 'reviewed@a.test']);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $request = RegistrationRequest::query()->whereRaw('lower(email) = ?', ['reviewed@a.test'])->firstOrFail();

        app(AdvanceRegistration::class)
            ->rejected($request, 'The company could not be verified.');

        $notification = SubscriptionNotification::query()->where('event', 'registration_rejected')->firstOrFail();

        $this->assertSame('reviewed@a.test', $notification->to_email);
        // Addressed by EMAIL, because an applicant has no tenant to be scoped to.
        $this->assertNull($notification->tenant_id);
        // A refusal always carries its reason.
        $this->assertStringContainsString('The company could not be verified.', $notification->body);
    }

    // ── Templates ─────────────────────────────────────────────────────────────────────────────

    /** Every event has both languages, and neither is a placeholder. */
    public function test_every_event_renders_in_arabic_and_english(): void
    {
        foreach (SubscriptionNotificationTemplates::events() as $event) {
            foreach (['ar', 'en'] as $locale) {
                $rendered = SubscriptionNotificationTemplates::render($event, $locale, [
                    'plan' => 'Growth', 'amount' => '499.00', 'currency' => 'SAR',
                    'date' => '2026-09-01', 'days' => 7, 'reason' => 'because', 'url' => '/x',
                ]);

                $this->assertNotSame('', $rendered['subject'], "{$event}/{$locale} has no subject");
                $this->assertNotSame('', $rendered['body'], "{$event}/{$locale} has no body");
            }
        }
    }

    /**
     * An unknown event is refused rather than sent as a friendly generic message.
     *
     * A message that says nothing is worse than no message: the customer is alerted and cannot act.
     */
    public function test_an_unknown_event_is_a_programming_error_not_a_vague_email(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(SubscriptionNotifier::class)
            ->notifyApplicant(RegistrationRequest::query()->firstOrNew(['email' => 'x@a.test']), 'invented_event');
    }
}
