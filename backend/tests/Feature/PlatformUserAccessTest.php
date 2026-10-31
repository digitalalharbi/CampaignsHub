<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Support\UserSessions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SUPERADMIN-ACCESS-001 — a person's access is restored from the console without anyone seeing or choosing their password.
 */
final class PlatformUserAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'operator-pass-1234']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u;
    }

    private function person(): User
    {
        return User::create(['name' => 'Sara', 'email' => 'sara-'.uniqid().'@agency.test', 'password' => 'sara-pass-1234']);
    }

    private function act(User $admin, User $target, array $body)
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/users/{$target->uuid}/access", $body);
    }

    public function test_only_a_platform_admin_acts_and_never_on_another_platform_admin(): void
    {
        $target = $this->person();
        $this->actingAs($this->person(), 'sanctum')->postJson("/api/v1/admin/users/{$target->uuid}/access", ['action' => 'revoke_sessions', 'reason' => 'lost laptop'])
            ->assertForbidden();

        $other = $this->admin();
        $this->act($this->admin(), $other, ['action' => 'revoke_sessions', 'reason' => 'just because'])->assertForbidden();
    }

    public function test_every_action_needs_a_reason_and_is_audited_with_it(): void
    {
        $admin = $this->admin();
        $target = $this->person();

        $this->act($admin, $target, ['action' => 'revoke_sessions'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->act($admin, $target, ['action' => 'revoke_sessions', 'reason' => 'Laptop stolen 2026-10-31'])->assertOk();

        $audit = AuditLog::query()->where('action', 'platform.user.revoke_sessions')->sole();
        $this->assertSame('Laptop stolen 2026-10-31', $audit->reason);
        $this->assertSame((int) $admin->getKey(), (int) $audit->user_id);
    }

    public function test_revoking_sessions_ends_every_session_signed_in_before_now_and_lets_a_new_sign_in_through(): void
    {
        $target = $this->person();
        $target->createToken('api');
        $session = $this->app['session.store'];
        $session->put(UserSessions::SESSION_KEY, Carbon::now()->subMinute()->getTimestamp());

        $this->act($this->admin(), $target, ['action' => 'revoke_sessions', 'reason' => 'Account takeover suspected'])->assertOk();

        $target->refresh();
        $this->assertTrue(UserSessions::isStale($target, $session), 'a session from before the cut-off is refused');
        $this->assertSame(0, $target->tokens()->count(), 'API tokens are gone');

        Carbon::setTestNow(Carbon::now()->addSecond());
        UserSessions::stamp($session);
        $this->assertFalse(UserSessions::isStale($target, $session), 'signing in again works');
        Carbon::setTestNow();
    }

    /** Sanctum treats the SPA origin as stateful — without it there is no cookie session to end. */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    private function browser(?string $sessionId): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->defaultCookies = [];
        $test = $this->withCredentials()->withHeaders($this->spa);

        return $sessionId === null ? $test : $test->withCookie(config('session.cookie'), $sessionId);
    }

    private function signIn(User $user, string $password): string
    {
        $this->browser(null)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $password])->assertOk();

        return $this->app['session']->getId();
    }

    public function test_after_the_operator_ends_the_sessions_every_device_is_signed_out_for_real(): void
    {
        $target = $this->person();
        $laptop = $this->signIn($target, 'sara-pass-1234');
        $phone = $this->signIn($target, 'sara-pass-1234');
        $this->browser($laptop)->getJson('/api/v1/auth/me')->assertOk();

        Carbon::setTestNow(Carbon::now()->addSecond());
        // The operator acts from their own client: none of the target's browser state travels with the request.
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->defaultCookies = [];
        $this->defaultHeaders = [];
        $this->withCredentials = false;
        $this->act($this->admin(), $target, ['action' => 'revoke_sessions', 'reason' => 'Account takeover suspected'])->assertOk();

        $this->browser($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->browser($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();

        Carbon::setTestNow(Carbon::now()->addSecond());
        $fresh = $this->signIn($target, 'sara-pass-1234');
        $this->browser($fresh)->getJson('/api/v1/auth/me')->assertOk();
        Carbon::setTestNow();
    }

    public function test_a_password_link_needs_the_operators_own_password_and_never_reaches_the_audit_trail(): void
    {
        $admin = $this->admin();
        $target = $this->person();

        $this->act($admin, $target, ['action' => 'password_link', 'reason' => 'Lost access to email'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->act($admin, $target, ['action' => 'password_link', 'reason' => 'Lost access to email', 'current_password' => 'wrong'])->assertStatus(422);

        $link = (string) $this->act($admin, $target, ['action' => 'password_link', 'reason' => 'Lost access to email', 'current_password' => 'operator-pass-1234'])
            ->assertOk()->json('data.link');

        $this->assertStringContainsString('/reset-password?token=', $link);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $audit = AuditLog::query()->where('action', 'platform.user.password_link')->sole();
        $this->assertStringNotContainsString((string) $q['token'], json_encode($audit->getAttributes()));
        $this->assertTrue(\Hash::check('sara-pass-1234', (string) $target->refresh()->password), 'the old password is untouched until the link is used');
    }

    public function test_resetting_two_factor_turns_it_off_and_ends_the_sessions(): void
    {
        $admin = $this->admin();
        $target = $this->person();
        $target->forceFill(['two_factor_enabled' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP'])->save();

        $this->act($admin, $target, ['action' => 'reset_mfa', 'reason' => 'Phone lost', 'current_password' => 'operator-pass-1234'])->assertOk();

        $target->refresh();
        $this->assertFalse($target->two_factor_enabled);
        $this->assertNull($target->two_factor_secret);
        $this->assertNotNull($target->sessions_valid_after);
    }

    public function test_signing_out_my_other_sessions_now_really_ends_them_and_keeps_this_one(): void
    {
        $user = $this->person();
        $laptop = $this->signIn($user, 'sara-pass-1234');
        Carbon::setTestNow(Carbon::now()->addSecond());
        $phone = $this->signIn($user, 'sara-pass-1234');

        // Before this fix the laptop stayed signed in: rotating the remember token touched no Redis session.
        $this->browser($phone)->deleteJson('/api/v1/me/sessions/others', ['current_password' => 'sara-pass-1234'])->assertOk();
        $phoneNow = $this->app['session']->getId();

        $this->browser($laptop)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->browser($phoneNow)->getJson('/api/v1/auth/me')->assertOk();
        Carbon::setTestNow();
    }
}
