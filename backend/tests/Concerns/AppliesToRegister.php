<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * Walking the gated registration path from a test (SIGNUP-002).
 *
 * Registering and having an account are two events now, and a dozen tests were written when they
 * were one. The point of a shared helper is that those tests keep asserting what they were about —
 * the membership, the portal, the menu — instead of each one growing its own copy of the journey and
 * quietly drifting from it.
 *
 * `apply()` returns the raw response so tests about the APPLICATION can inspect it. `applyAndVerify()`
 * walks it to a workspace, and deliberately fails loudly if it does not get one: a test that expects
 * an active account must not silently continue against an applicant who is still in a queue.
 */
trait AppliesToRegister
{
    /** @var array<string, string> */
    protected array $spaOrigin = ['Origin' => 'http://localhost:5173'];

    /** @param array<string, mixed> $overrides */
    protected function apply(array $overrides = []): TestResponse
    {
        /*
         * A catalogue has to exist before anybody can name a plan.
         *
         * `plan_code` is required since PLAN-PAID-001, and it is validated against what is actually on
         * sale — so a test with an empty `subscription_plans` table cannot register at all. Seeding
         * here rather than in thirty `setUp()` methods keeps the requirement in the one place that
         * introduced it. The seeder upserts by code, so calling it twice is free.
         */
        if (! SubscriptionPlan::query()->where('code', 'starter')->exists()) {
            $this->seed(SubscriptionPlanSeeder::class);
        }

        return $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/register', array_merge([
            'tenant_name' => 'New Workspace',
            'name' => 'New Owner',
            'email' => 'new@owner.test',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
            /*
             * A plan and a term, because since PLAN-PAID-001 there is no unpriced way in.
             *
             * «البداية» is the cheapest thing on sale and the plan most real applicants pick, which
             * makes it the right default for a helper whose job is to be the ordinary journey. A test
             * about a different plan overrides it, as several do.
             */
            'plan_code' => 'starter',
            'billing_interval' => 'monthly',
            /*
             * A mobile number, because PHONE-VERIFY-001 made one mandatory.
             *
             * Unique per call: the number is now checked for duplicates in E.164, so a fixed value
             * would make the SECOND application in any test fail with a message about somebody else's
             * account — a confusing way to discover a rule that is working correctly.
             */
            'phone' => self::aFreshSaudiNumber(),
        ], $overrides));
    }

    /**
     * A Saudi mobile number nothing else in this run has used.
     *
     * Written in the national form on purpose. It is what a customer types, it is what the duplicate
     * check has to normalise before it can compare, and a helper that only ever produced `+966…`
     * would quietly stop exercising the reading rule that makes the two the same number.
     */
    protected static function aFreshSaudiNumber(): string
    {
        static $seq = 0;
        $seq++;

        return '05'.str_pad((string) (10_000_000 + $seq + random_int(0, 9_000_000)), 8, '0', STR_PAD_LEFT);
    }

    /**
     * The dev verification token for an application. Exposed outside production only — see
     * RegistrationVerificationService — which is exactly the condition tests run under.
     */
    protected function verificationTokenFrom(TestResponse $response): string
    {
        $link = (string) $response->json('data.verification.dev_link');

        $this->assertNotSame('', $link, 'no dev verification link was issued for this application');

        return explode('token=', $link)[1];
    }

    /**
     * Apply, prove the email, PAY, and come back with the workspace it produced.
     *
     * The payment step is not scaffolding around the test — it is the journey. Since PLAN-PAID-001
     * every application waits at the payment gate, and the only thing that opens it is a webhook the
     * gateway signed. A helper that reached a workspace any other way would be proving that tests can
     * do something customers cannot.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{user: User, registration: RegistrationRequest, verify: TestResponse}
     */
    protected function applyAndVerify(array $overrides = []): array
    {
        $applied = $this->apply($overrides)->assertStatus(202);
        $email = (string) ($overrides['email'] ?? 'new@owner.test');

        $verify = $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk();

        $registration = RegistrationRequest::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->latest('created_at')->firstOrFail();

        /*
         * The mobile gate, then the payment gate — in the order the policy imposes them.
         *
         * Both are part of the journey rather than scaffolding around it: since PHONE-VERIFY-001 no
         * account exists without a verified number, and since PLAN-PAID-001 none exists without a
         * settled charge. A helper that reached a workspace without clearing both would be proving
         * that tests can do something customers cannot.
         */
        if (! $registration->isProvisioned()) {
            $this->verifyMobileFor($registration);
            $registration = $registration->refresh();
        }

        if (! $registration->isProvisioned()) {
            $this->payForRegistration($registration);
            $registration = $registration->refresh();
        }

        $this->assertTrue(
            $registration->isProvisioned(),
            'the application did not become a workspace — the registration policy still has a gate open',
        );

        return [
            'user' => User::where('email', $email)->firstOrFail(),
            'registration' => $registration,
            'verify' => $verify,
        ];
    }

    /**
     * Answer the mobile challenge, the way an applicant does.
     *
     * The code is requested through `resend` and read back from `dev_code`, which the backend exposes
     * outside production only — the same affordance that keeps the email link walkable without a mail
     * provider. Nothing here writes `mobile_verified_at`; the endpoint does, after checking the code.
     */
    protected function verifyMobileFor(RegistrationRequest $registration): void
    {
        $issued = $this->withHeaders($this->spaOrigin)
            ->postJson("/api/v1/auth/registration/{$registration->getKey()}/resend", ['channel' => 'mobile'])
            ->assertOk();

        $code = (string) $issued->json('data.verification.dev_code');
        $this->assertNotSame('', $code, 'no dev code was issued for the mobile challenge');

        $this->withHeaders($this->spaOrigin)
            ->postJson("/api/v1/auth/registration/{$registration->getKey()}/verify-mobile", ['code' => $code])
            ->assertOk();
    }

    /**
     * Take the application through a real, verified payment.
     *
     * Moyasar is configured here rather than in each test's `setUp`, because the gateway a test uses
     * is not what any of them are about; what matters is that the event goes through
     * `ApplySubscriptionPaymentEvent` with a signature it checks, exactly as a live one would. The
     * amount is read back from the charge the platform opened, so a test cannot accidentally pay an
     * amount the platform never asked for and still be activated.
     */
    protected function payForRegistration(RegistrationRequest $registration): void
    {
        config([
            'services.moyasar.secret_key' => 'sk_test',
            'services.moyasar.webhook_token' => 'shared-secret',
        ]);

        $this->postJson("/api/v1/auth/registration/{$registration->getKey()}/checkout", ['commitment_agreed' => true])->assertOk();

        $payment = SubscriptionPayment::query()
            ->where('registration_request_id', $registration->getKey())
            ->latest('created_at')->firstOrFail();

        /*
         * Moyasar's own answer, faked (PAY-CONFIRM-001).
         *
         * A Moyasar webhook authenticates with a shared secret carried inside the body, so the
         * product no longer settles on what that body says — it re-reads the charge from
         * `GET /v1/payments/{id}` over its own connection. Without this fake the fetch fails, nothing
         * settles, and every journey that pays for a registration stops at the payment gate.
         */
        Http::fake([
            'api.moyasar.com/v1/payments/*' => Http::response([
                'id' => 'pay_'.$payment->getKey(),
                'status' => 'paid',
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ], 200),
        ]);

        $this->postJson('/api/v1/payments/webhook/moyasar', [
            'id' => 'evt_'.$payment->getKey(),
            'type' => 'payment_paid',
            'secret_token' => 'shared-secret',
            'data' => [
                'id' => 'pay_'.$payment->getKey(),
                'status' => 'paid',
                // Halalas — the smallest unit, which is what the gateway reports.
                'amount' => (int) round(((float) $payment->amount) * 100),
                'currency' => $payment->currency,
                'metadata' => ['reference' => $payment->idempotency_key],
            ],
        ])->assertOk()->assertJsonPath('data.verified', true);
    }
}
