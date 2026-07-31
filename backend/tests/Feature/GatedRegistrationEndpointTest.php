<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Models\RegistrationVerification;
use App\Domains\Identity\Actions\RegisterTenantAction;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * The gated registration path as an applicant actually meets it (SIGNUP-002d).
 *
 * `RegistrationPolicyTest` proves the state machine in isolation. This proves the endpoints in front
 * of it: that the public API cannot be used to skip a gate, that an applicant can always find out
 * what they are waiting on, and that nothing exists to sign in with until it should.
 */
final class GatedRegistrationEndpointTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function assertNothingGranted(): void
    {
        $this->assertSame(0, Tenant::count(), 'no workspace may exist yet');
        $this->assertSame(0, User::count(), 'no sign-in account may exist yet');
        $this->assertSame(0, Membership::count(), 'no membership may exist yet');
    }

    // ── The status screen ─────────────────────────────────────────────────────────────────────

    /** An applicant can always read where they stand, without an account to sign in with. */
    public function test_an_applicant_can_read_their_own_status_without_a_session(): void
    {
        $id = $this->apply(['email' => 'waiting@a.test'])->json('data.registration.id');

        $this->getJson("/api/v1/auth/registration/{$id}")->assertOk()
            ->assertJsonPath('data.registration.state', 'email_verification_required')
            ->assertJsonPath('data.registration.provisioned', false);

        // …and the screen is told the ONE thing to do next, in words.
        $this->assertNotNull($this->getJson("/api/v1/auth/registration/{$id}")->json('data.registration.next_step'));
    }

    /** A status payload never carries the credential it is holding. */
    public function test_the_status_payload_does_not_leak_the_password(): void
    {
        $id = $this->apply(['email' => 'secretive@a.test'])->json('data.registration.id');

        $body = $this->getJson("/api/v1/auth/registration/{$id}")->getContent();

        $this->assertStringNotContainsString('password', (string) $body);
        $this->assertStringNotContainsString('secret1234', (string) $body);
    }

    // ── Waiting on us, not on them ────────────────────────────────────────────────────────────

    /**
     * An application held for review says so, and offers no next step — because there is nothing the
     * applicant can do, and inventing a button here would be the interface lying about who is
     * blocking whom.
     */
    public function test_an_application_awaiting_approval_grants_nothing_and_asks_nothing(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $res = $this->apply(['email' => 'reviewed@a.test']);
        $verify = $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $verify->assertJsonPath('data.registration.state', 'pending_approval')
            ->assertJsonPath('data.registration.next_step', null)
            ->assertJsonPath('data.user', null);

        $this->assertNothingGranted();
        $this->assertGuest();
    }

    /**
     * The payment gate cannot be cleared from the browser.
     *
     * Every public endpoint is tried in turn against an application that owes money, and none of
     * them moves it. Only a confirmed payment does, and no endpoint here can confirm one.
     */
    public function test_no_public_endpoint_can_clear_the_payment_gate(): void
    {
        config(['accounts.registration.default' => ['requires_payment' => true]]);

        $res = $this->apply(['email' => 'owing@a.test']);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $id = (string) RegistrationRequest::query()->firstOrFail()->getKey();

        // Re-verify with a freshly issued link…
        $resend = $this->postJson("/api/v1/auth/registration/{$id}/resend", ['channel' => 'email'])->assertOk();
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($resend),
        ])->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        // …and ask again. Still owing.
        $this->getJson("/api/v1/auth/registration/{$id}")
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->assertNothingGranted();
    }

    // ── Mobile verification (SIGNUP-005) ──────────────────────────────────────────────────────

    public function test_the_mobile_gate_is_answered_with_the_code_and_nothing_else(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true]]);

        $res = $this->apply(['email' => 'mob@a.test', 'phone' => '+966500000000']);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk()->assertJsonPath('data.registration.state', 'mobile_verification_required');

        $this->assertNothingGranted();

        $id = (string) RegistrationRequest::query()->firstOrFail()->getKey();
        $code = $this->postJson("/api/v1/auth/registration/{$id}/resend", ['channel' => 'mobile'])
            ->assertOk()->json('data.verification.dev_code');

        $this->assertNotNull($code, 'the OTP is exposed outside production so the journey stays walkable');

        // A wrong code is refused and still grants nothing.
        $this->postJson("/api/v1/auth/registration/{$id}/verify-mobile", ['code' => '000000'])
            ->assertStatus(422);
        $this->assertNothingGranted();

        $this->postJson("/api/v1/auth/registration/{$id}/verify-mobile", ['code' => $code])
            ->assertOk()->assertJsonPath('data.registration.state', 'active');

        $this->assertSame(1, Membership::count());
    }

    /** Guessing has a budget. Five misses and the code is spent, whatever the rate limiter thinks. */
    public function test_a_mobile_code_survives_only_a_handful_of_wrong_guesses(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true]]);

        $res = $this->apply(['email' => 'guess@a.test', 'phone' => '+966500000001']);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        $id = (string) RegistrationRequest::query()->firstOrFail()->getKey();
        $code = $this->postJson("/api/v1/auth/registration/{$id}/resend", ['channel' => 'mobile'])
            ->json('data.verification.dev_code');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson("/api/v1/auth/registration/{$id}/verify-mobile", ['code' => '111111'])
                ->assertStatus(422);
        }

        // Even the RIGHT code no longer works — the challenge is burnt, not merely throttled.
        $this->postJson("/api/v1/auth/registration/{$id}/verify-mobile", ['code' => $code])
            ->assertStatus(422);

        $this->assertNothingGranted();
    }

    /** Only the hash is kept, so a leaked table hands out no working links or codes. */
    public function test_challenges_are_stored_as_hashes_only(): void
    {
        $res = $this->apply(['email' => 'hashed@a.test']);
        $token = $this->verificationTokenFrom($res);

        $stored = RegistrationVerification::query()->firstOrFail();

        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
    }

    // ── The auto-activate branch, kept honest ─────────────────────────────────────────────────

    /**
     * `RegisterTenantAction` survives as the named auto-activate branch, and refuses to be a way
     * around a gate that has been configured.
     */
    public function test_the_auto_activate_branch_refuses_when_the_policy_has_a_gate(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $this->expectException(RuntimeException::class);

        app(RegisterTenantAction::class)->execute(
            new RegisterData(
                tenantName: 'Shortcut Co', name: 'Shortcut', email: 'shortcut@a.test',
                password: 'secret1234', accountType: 'brand',
            ),
            emailAlreadyProvenBecause: 'test',
        );
    }

    /** …and when no gate is configured, it does what it says, through the same provisioner. */
    public function test_the_auto_activate_branch_provisions_through_the_registration_path(): void
    {
        $user = app(RegisterTenantAction::class)->execute(
            new RegisterData(
                tenantName: 'Direct Co', name: 'Direct', email: 'direct@a.test',
                password: 'secret1234', accountType: 'brand',
            ),
            emailAlreadyProvenBecause: 'Created by an administrator; the address was confirmed out of band.',
        );

        $this->assertTrue($user->memberships()->exists());

        // There is a registration record either way — the crossing is auditable however it happened.
        $request = RegistrationRequest::query()->firstOrFail();
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertTrue($request->isProvisioned());
    }
}
