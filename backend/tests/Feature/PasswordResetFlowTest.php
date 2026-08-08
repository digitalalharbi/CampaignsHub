<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Mail\SecurityAlertMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAIL-009 — an account whose password is lost stops being an account that is lost.
 *
 * `/auth/forgot-password` shipped months ago and led nowhere: it matched the address, wrote a line to
 * the log, and returned success. No token was issued and no endpoint consumed one, so «تحقق من بريدك»
 * pointed at an email that was never sent and a link that had nothing to open. Every member added
 * through the team screen was in the same position permanently — created with a random 24-character
 * password, and no route to a known one.
 *
 * These tests are about the properties that make a reset safe rather than merely present.
 */
final class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email = 'member@example.com'): User
    {
        return User::create([
            'name' => 'Member', 'email' => $email, 'password' => Hash::make('original-password'),
        ]);
    }

    /**
     * Issue a real token the way the product does, and hand back the plaintext for the test to use.
     *
     * The email channel is pointed at a provider that reports credentials. Without that, the default
     * `NullEmailProvider` answers «not configured», `TransactionalMailer` composes nothing, and the
     * token would be issued with no message to read it out of — which is the product behaving exactly
     * as it should on an install with no mailer, and useless as a fixture.
     */
    private function issueFor(User $user): string
    {
        config(['providers.channels.email' => ConfiguredTestEmailProvider::class]);
        Mail::fake();
        app(PasswordResetService::class)->request((string) $user->email);

        // The row stores only the hash, so the plaintext has to come from the message that was built.
        // Reading it back out of the ledger would prove nothing about the link a person receives.
        $secret = null;
        Mail::assertSent(CredentialMail::class, function ($mail) use (&$secret): bool {
            parse_str((string) parse_url((string) $mail->url, PHP_URL_QUERY), $query);
            $secret = $query['token'] ?? null;

            return true;
        });

        $this->assertIsString($secret, 'the reset message carried no token');

        return $secret;
    }

    /**
     * A person who asks gets a working link, and the link works exactly once.
     *
     * The second half matters more than the first: a reset token that survives its own use is a
     * standing key to the account for anybody who later reads the mailbox.
     */
    public function test_a_reset_link_sets_the_password_and_cannot_be_used_again(): void
    {
        $user = $this->user();
        $token = $this->issueFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'yet-another-password',
            'password_confirmation' => 'yet-another-password',
        ])->assertStatus(422);

        // And the first password still stands: the refused attempt changed nothing.
        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
    }

    /**
     * The form answers identically for an address nobody holds.
     *
     * An unauthenticated endpoint that responds differently for a known address is a directory of who
     * has an account here — and no amount of throttling makes that not a disclosure.
     */
    public function test_an_unknown_address_is_answered_exactly_like_a_known_one(): void
    {
        Mail::fake();
        $this->user('real@example.com');

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'real@example.com']);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
        // Including the delivery state, which is exactly the fact that would give it away.
        $this->assertSame($known->json('data'), $unknown->json('data'));
    }

    /** A link past its expiry is refused, and refused with the same words as a wrong one. */
    public function test_an_expired_link_is_refused_and_says_nothing_about_why(): void
    {
        $user = $this->user();
        $token = $this->issueFor($user);

        DB::table('password_reset_tokens')->where('email', $user->email)
            ->update(['expires_at' => Carbon::now()->subMinute()]);

        $expired = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertStatus(422);

        $wrong = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => Str::random(64),
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertStatus(422);

        $this->assertSame(
            $expired->json('errors.token'), $wrong->json('errors.token'),
            'the two failures are distinguishable, which tells a stranger whether a request is open',
        );
    }

    /**
     * Asking twice replaces the window rather than widening it.
     *
     * Leaving the first token usable would mean «send it again» quietly doubles how long the account
     * is reachable by anybody holding either message.
     */
    public function test_a_second_request_invalidates_the_first_link(): void
    {
        $user = $this->user();
        $first = $this->issueFor($user);
        $second = $this->issueFor($user);

        $this->assertNotSame($first, $second);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $first,
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $second,
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertOk();
    }

    /**
     * A reset ends every session the account had, and the remember-me cookies with them.
     *
     * Somebody resetting because a device was stolen keeps that device signed in otherwise — which is
     * the single outcome the reset was performed to prevent.
     */
    public function test_a_reset_ends_the_sessions_the_account_already_had(): void
    {
        $user = $this->user();
        $before = (string) $user->remember_token;

        DB::table('sessions')->insert([
            'id' => 'session-to-be-ended', 'user_id' => $user->getKey(),
            'ip_address' => '10.0.0.1', 'user_agent' => 'test', 'payload' => 'x',
            'last_activity' => time(),
        ]);
        // Another person's session, which must survive untouched.
        $other = $this->user('other@example.com');
        DB::table('sessions')->insert([
            'id' => 'session-that-stays', 'user_id' => $other->getKey(),
            'ip_address' => '10.0.0.2', 'user_agent' => 'test', 'payload' => 'x',
            'last_activity' => time(),
        ]);

        $token = $this->issueFor($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertOk();

        $this->assertNull(DB::table('sessions')->find('session-to-be-ended'));
        $this->assertNotNull(DB::table('sessions')->find('session-that-stays'));
        $this->assertNotSame($before, (string) $user->fresh()->remember_token);
    }

    /**
     * The person is told their password changed, at the address that was just used.
     *
     * If the reset was theirs it is a receipt. If it was not, it is the only message that will ever
     * reach them about it — and it arrives somewhere the attacker cannot suppress it from.
     */
    public function test_a_completed_reset_tells_the_account_holder_it_happened(): void
    {
        $user = $this->user();
        $token = $this->issueFor($user);

        Mail::fake();
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-here', 'password_confirmation' => 'new-password-here',
        ])->assertOk();

        Mail::assertSent(SecurityAlertMail::class);
    }

    /**
     * A confirmation that does not match is refused by the SERVER.
     *
     * The browser checks this too, as a courtesy. Treating a browser check as the control is how a
     * validation rule quietly stops being enforced.
     */
    public function test_a_mismatched_confirmation_is_refused_server_side(): void
    {
        $user = $this->user();
        $token = $this->issueFor($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'new-password-here', 'password_confirmation' => 'something-else-entirely',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }
}
