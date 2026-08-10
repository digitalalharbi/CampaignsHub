<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Requests\Services\ContactVerificationService;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * LOGIN-OTP-001 — the email code IS the production door, so every property has to be real.
 *
 * These tests are written against the way this fails in the wild rather than against the happy path:
 * a code that still works after it has been used, a code that survives being replaced, a resend loop
 * that turns the sign-in form into a way to send somebody a message every second, and an endpoint
 * that quietly tells a stranger which addresses have accounts here.
 */
final class EmailSignInTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SPA's origin, on every call.
     *
     * Sanctum only engages its stateful path — and therefore only attaches a session — for requests
     * whose Origin matches the frontend. Without it `session()->regenerate()` throws «Session store
     * not set on request», which is a test artefact rather than a defect: a browser always sends one.
     *
     * @var array<string, string>
     */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function verifiedUser(string $email = 'owner@example.test'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** @return array{id: string, code: string} */
    private function codeFor(string $typed): array
    {
        $res = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => $typed])
            ->assertOk();

        return [
            'id' => (string) $res->json('data.verification_id'),
            'code' => (string) $res->json('data.dev_code'),
        ];
    }

    /** Move every challenge's clock back, so a resend is allowed without sleeping in a test. */
    private function forgetCooldown(): void
    {
        ContactVerification::query()->update(['last_sent_at' => Carbon::now()->subMinutes(5)]);
    }

    public function test_a_code_signs_a_user_in(): void
    {
        $user = $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.user.email', $user->email);

        $this->assertAuthenticatedAs($user);
    }

    /** The address is matched case-insensitively — «Owner@» and «owner@» are one account. */
    public function test_the_address_is_matched_without_regard_to_case(): void
    {
        $user = $this->verifiedUser('Owner@Example.test');
        ['id' => $id, 'code' => $code] = $this->codeFor('OWNER@EXAMPLE.TEST');

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor('owner@example.test');

        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $wrong])
            ->assertStatus(422);

        $this->assertGuest();
    }

    /**
     * A code is spent once.
     *
     * The failure this guards is not theoretical: `verify()` in the shared service marks a challenge
     * VERIFIED and leaves it usable, which is right for the flows that verify once and consume the
     * proof later. For a credential it means six digits read over somebody's shoulder open a session
     * every time they are posted, for the whole ten minutes.
     */
    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk();

        // Put the test client back where a second person posting the same six digits would be.
        Auth::guard('web')->logout();
        $this->flushSession();

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor('owner@example.test');

        ContactVerification::query()->whereKey($id)->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertGuest();
    }

    /** Asking for a new code retires the old one — otherwise «that wasn't mine» retires nothing. */
    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $user = $this->verifiedUser();
        $first = $this->codeFor($user->email);

        $this->forgetCooldown();
        $second = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $first['id'], 'code' => $first['code']])
            ->assertStatus(422);

        $this->assertGuest();

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $second['id'], 'code' => $second['code']])
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The resend cooldown is enforced on the SERVER.
     *
     * The countdown the visitor sees lives in a browser tab and disappears the moment anybody posts
     * to the endpoint directly. Per-IP throttling does not cover this: the thing being bounded is
     * messages to ONE address, which is what stops the sign-in form being usable to harass somebody.
     */
    public function test_a_second_code_inside_the_cooldown_is_refused(): void
    {
        $this->verifiedUser();
        $this->codeFor('owner@example.test');

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'owner@example.test'])
            ->assertStatus(422);
    }

    /**
     * A code that was USED imposes no wait on the next sign-in.
     *
     * The window exists to stop a stranger mailing somebody repeatedly, and consuming a challenge
     * requires holding the code — which is exactly what such a stranger does not have. Without this
     * exemption the rule is «one sign-in per minute per address», and somebody who signs out and
     * straight back in is told to wait by a control that was never aimed at them.
     */
    public function test_signing_in_again_immediately_after_a_used_code_is_allowed(): void
    {
        $user = $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk();

        Auth::guard('web')->logout();
        $this->flushSession();

        // No `forgetCooldown()` here — that is the whole point.
        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => $user->email])
            ->assertOk();
    }

    /** An UNUSED code still holds the window shut, which is the property that bounds harassment. */
    public function test_an_unused_code_still_holds_the_window_shut(): void
    {
        $this->verifiedUser();
        $this->codeFor('owner@example.test');

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'owner@example.test'])
            ->assertStatus(422);
    }

    public function test_a_resend_is_allowed_once_the_cooldown_has_passed(): void
    {
        $this->verifiedUser();
        $this->codeFor('owner@example.test');
        $this->forgetCooldown();

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'owner@example.test'])
            ->assertOk();
    }

    /**
     * An address nobody holds gets exactly the same answer as one somebody does.
     *
     * This is the whole anti-enumeration property, and it is asserted on the SHAPE of the response
     * rather than on a message, because a difference anywhere — a missing field, a different status —
     * is enough to build a directory of who has an account here.
     */
    public function test_an_unknown_address_is_answered_identically(): void
    {
        $this->verifiedUser();

        $known = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'owner@example.test'])->assertOk();
        $unknown = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'nobody@example.test'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        $this->assertSame(
            array_keys((array) $known->json('data')),
            array_keys((array) $unknown->json('data')),
        );
        $this->assertNotNull($unknown->json('data.verification_id'));
    }

    /** And the code for an address with no account fails exactly as a wrong code does: 422, no session. */
    public function test_a_correct_code_for_an_unknown_address_opens_nothing(): void
    {
        ['id' => $id, 'code' => $code] = $this->codeFor('nobody@example.test');

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(422);

        $this->assertGuest();
    }

    /**
     * A code minted for anything else is not a platform credential, whatever it verifies.
     *
     * The challenge is created through the service directly rather than by driving the client
     * portal, so what is being tested is the purpose check itself and not the portal's preconditions:
     * this must hold for EVERY other purpose — portal sign-in, registration, contact verification —
     * and a test that went through one of them would only prove it for that one.
     */
    public function test_a_code_from_another_purpose_is_not_a_platform_credential(): void
    {
        $this->verifiedUser();

        $issued = app(ContactVerificationService::class)->start('email', 'owner@example.test', 'client_portal_login');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/verify', [
            'verification_id' => $issued['id'],
            'code' => (string) $issued['dev_code'],
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_sign_in_with_a_code(): void
    {
        $user = $this->verifiedUser();
        $user->forceFill(['disabled_at' => now()])->save();

        ['id' => $id, 'code' => $code] = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertStatus(403);

        $this->assertGuest();
    }

    /** Only the hash is ever stored. A plaintext code at rest is the credential itself, kept. */
    public function test_the_code_is_never_stored_in_the_clear(): void
    {
        $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor('owner@example.test');

        $row = ContactVerification::query()->findOrFail($id);

        $this->assertSame(hash('sha256', $code), $row->code_hash);
        $this->assertStringNotContainsString($code, json_encode($row->getAttributes(), JSON_THROW_ON_ERROR));
    }

    /** Issue and success are both recorded, and neither record carries the code or its hash. */
    public function test_the_security_trail_records_the_attempt_without_the_secret(): void
    {
        $user = $this->verifiedUser();
        ['id' => $id, 'code' => $code] = $this->codeFor($user->email);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/verify', ['verification_id' => $id, 'code' => $code])
            ->assertOk();

        $requested = AuditLog::query()->where('action', 'auth.email_code.requested')->firstOrFail();
        $signedIn = AuditLog::query()->where('action', 'auth.email_code.signed_in')->firstOrFail();

        $this->assertSame((string) $user->id, (string) $signedIn->user_id);

        foreach ([$requested, $signedIn] as $entry) {
            $serialised = json_encode($entry->after, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($code, (string) $serialised);
            $this->assertStringNotContainsString(hash('sha256', $code), (string) $serialised);
        }
    }

    /**
     * With no mail provider, nothing is claimed to have been sent.
     *
     * `awaiting_provider_credentials` is the whole READY_FOR_CREDENTIALS position expressed in one
     * field: the flow exists, the code was minted, and the product does not say it reached anybody.
     */
    public function test_delivery_is_reported_honestly_when_no_provider_is_configured(): void
    {
        config(['requests.verification.providers.email' => false]);

        $status = (string) $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'owner@example.test'])
            ->assertOk()
            ->json('data.delivery_status');

        /*
         * The status now comes from the mail LEDGER rather than from a configuration guess.
         *
         * It used to be the literal `awaiting_provider_credentials`, written before anything was
         * attempted because nothing ever was. Now `TransactionalMailer` answers — `awaiting_credentials`
         * when the channel reports no provider, `sandbox` when the driver reaches nobody — and the
         * claim worth pinning is the one the page depends on: whatever this says, it must never be a
         * state that means «it arrived».
         */
        $this->assertNotContains($status, ['sent', 'delivered', 'queued']);
    }

    /**
     * The dev code is hard-gated: production never returns it, whatever the config says.
     *
     * Asserted on the gate itself rather than through an HTTP call, because switching the
     * application to `production` mid-test also switches on the middleware a browser satisfies and a
     * test client does not — the 419 that produces would be a test artefact, and it would be sitting
     * in front of the one line that actually matters. `dev_code` in the response is
     * `exposeDevSecrets() ? $code : null` and nothing else, so this is the same claim.
     */
    public function test_production_never_returns_the_code_to_the_browser(): void
    {
        config(['requests.verification.expose_dev_code' => true]);

        $this->assertTrue(ContactVerificationService::exposeDevSecrets(), 'the escape hatch is open outside production');

        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse(
            ContactVerificationService::exposeDevSecrets(),
            'the code was exposable in production even with the config override on',
        );
    }

    /** The password endpoint is kept, deliberately — it is the DEV/E2E path and it must still work. */
    public function test_the_password_path_still_signs_a_user_in(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user->fresh());
    }
}
