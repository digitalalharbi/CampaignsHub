<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Support\SessionRevocations;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * ACCESS-EXIT-003 — a session that was signed out cannot be brought back by a late write.
 *
 * ## What was measured, before any of this existed
 *
 * `logout` is correct and was never the defect: it calls `Auth::logout()`, `invalidate()` and
 * `regenerateToken()`, and the session's Redis key really is deleted — `EXISTS` 1 → 0, `TTL` -2,
 * observed over real HTTP against the running server. That eliminated «the old session survived».
 *
 * What it did not eliminate is the opposite direction. A request that STARTED before the sign-out
 * loaded the authenticated payload into memory; when it finishes, `StartSession` writes that payload
 * back under the SAME session id it was loaded with. Redis has no opinion about the order — it
 * simply receives a key it had deleted, holding the bytes it used to hold.
 *
 * The measurement, byte for byte, with no sleeps, retries or scans:
 *
 * ```
 * login=200 · me=200 · key EXISTS=1 (287 bytes)
 * logout=200 · key EXISTS=0 TTL=-2
 * me with the pre-logout jar          → 401   ← control: the cookie alone authenticates nothing
 * restore the 287 bytes under the key → OK
 * me with the pre-logout jar          → 200   ← the session is signed in again
 * ```
 *
 * So sign-out lasted exactly as long as nobody wrote the payload back, and the cookie in the
 * browser stayed a valid credential for the remainder of the original session's two hours.
 *
 * ## Why the fix is a marker and not a lock
 *
 * The payload cannot be the record of its own revocation — resurrection replaces the payload
 * wholesale, so any flag inside it is replaced too. The revocation therefore lives OUTSIDE the
 * session, keyed by a SHA-256 of the session id (never the id itself, in the cache or anywhere
 * else), and is checked on every request that carries a session.
 *
 * That keeps the blast radius at one session id: no global sign-out, no serialisation of concurrent
 * requests, no lock around dashboard traffic. Another device belonging to the same person holds a
 * different session id and is untouched, which the tests below state as a requirement rather than a
 * hope.
 *
 * The client-side barrier (`ACCESS-EXIT-002`) stays: it stops most of these late requests from being
 * sent at all. It is necessary and it is not sufficient — a request already in flight when someone
 * clicks Sign out cannot be recalled from the browser, which is why the server now refuses it.
 */
final class StaleSessionResurrectionTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    /** Sanctum treats the SPA origin as stateful — without it there is no cookie session to revoke. */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Race', 'slug' => 'race', 'status' => 'active']);
        $user = User::create(['name' => 'Racer', 'email' => 'race@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
    }

    /**
     * One request, from a browser that holds `$sessionId` — and from a CLEAN process.
     *
     * The reset is not tidiness, it is the difference between testing the product and testing the
     * harness. In production every request is its own process; in a feature test the session `Store`
     * and the auth guards are container singletons that outlive a request, and `Store::loadSession()`
     * merges the stored payload INTO whatever is already in memory
     * (`array_replace($this->attributes, …)`). So the previous request's `login_web_*` key survives
     * even when the store says the session was destroyed — a first draft of this test «passed» a
     * logout it had never actually performed on the session it was inspecting.
     *
     * Passing `null` means a browser holding no cookie at all.
     *
     * `withCredentials()` is required and not decoration either: a JSON request in a feature test
     * sends NO cookies without it (`prepareCookiesForJsonRequest`), so every `withCookie` here would
     * be silently dropped and each request would open a brand-new session — which looks exactly like
     * a working sign-out and proves nothing.
     */
    private function browser(?string $sessionId): self
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->defaultCookies = [];

        $test = $this->withCredentials()->withHeaders($this->spa);

        return $sessionId === null ? $test : $test->withCookie(config('session.cookie'), $sessionId);
    }

    /** Sign in from a browser with no cookie; returns the id of the session that was opened. */
    private function signIn(): string
    {
        $this->browser(null)
            ->postJson('/api/v1/auth/login', ['email' => 'race@t.test', 'password' => 'secret123'])
            ->assertOk();

        return $this->app['session']->getId();
    }

    /**
     * Read and write the session store the way a concurrent request does — through the handler.
     *
     * Deliberately at the handler level rather than at Redis: the defect is not about Redis, it is
     * about a payload being written back under an id that has been signed out, and every session
     * backend this application can be configured with has exactly that operation. The payload is
     * carried as opaque bytes and never unserialized — a late write copies bytes.
     */
    private function readPayload(string $sessionId): string
    {
        return (string) $this->app['session']->getHandler()->read($sessionId);
    }

    private function writePayloadBack(string $sessionId, string $payload): void
    {
        $this->app['session']->getHandler()->write($sessionId, $payload);
    }

    /**
     * **The defect, pinned.** Restore the bytes, and the signed-out cookie signs in again.
     *
     * Written as a sequence of requests rather than as a timing test: a race is only hard to
     * reproduce while you insist on reproducing the timing. What the race PRODUCES is a payload
     * written back under a signed-out id, and that is a state, not a moment.
     */
    public function test_a_session_restored_after_logout_cannot_authenticate(): void
    {
        $sessionId = $this->signIn();
        $payload = $this->readPayload($sessionId);
        $this->assertNotSame('', $payload, 'the signed-in session must be readable before we can restore it');

        $this->browser($sessionId)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame('', $this->readPayload($sessionId), 'logout must destroy the session it signed out');

        // The late write: request A, which loaded this session before the sign-out, finishing after it.
        $this->writePayloadBack($sessionId, $payload);
        $this->assertNotSame('', $this->readPayload($sessionId), 'the resurrection must be in place to be refused');

        $this->browser($sessionId)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** And it stays refused — a revoked id is not a one-shot rejection that clears itself. */
    public function test_the_refusal_survives_repetition(): void
    {
        $sessionId = $this->signIn();
        $payload = $this->readPayload($sessionId);

        $this->browser($sessionId)->postJson('/api/v1/auth/logout')->assertOk();

        foreach (range(1, 3) as $ignored) {
            $this->writePayloadBack($sessionId, $payload);
            $this->browser($sessionId)->getJson('/api/v1/auth/me')->assertUnauthorized();
        }
    }

    /**
     * The same person's other device keeps working — the whole reason this is scoped to one id.
     *
     * A global "sign this user out everywhere" would satisfy the test above and be a worse product:
     * closing a tab on the laptop would end the session on the phone.
     */
    public function test_another_session_for_the_same_user_is_untouched(): void
    {
        $laptop = $this->signIn();
        $phone = $this->signIn();
        $this->assertNotSame($laptop, $phone);

        $this->browser($laptop)->postJson('/api/v1/auth/logout')->assertOk();

        $this->browser($phone)->getJson('/api/v1/auth/me')->assertOk();
    }

    /** Signing in again after signing out gives a working session, on a new id. */
    public function test_a_new_sign_in_after_logout_works(): void
    {
        $revoked = $this->signIn();
        $this->browser($revoked)->postJson('/api/v1/auth/logout')->assertOk();

        $fresh = $this->signIn();

        $this->assertNotSame($revoked, $fresh);
        $this->browser($fresh)->getJson('/api/v1/auth/me')->assertOk();
    }

    /**
     * The marker never holds the session id, in its key or in its value.
     *
     * A revocation list is a list of live credentials until it is hashed. This one is written as a
     * SHA-256 and is only ever compared against another SHA-256, so reading the store tells whoever
     * reads it which sessions ended and nothing they could use.
     */
    public function test_the_revocation_marker_holds_no_raw_session_id(): void
    {
        $sessionId = $this->signIn();
        $this->browser($sessionId)->postJson('/api/v1/auth/logout')->assertOk();

        $revocations = app(SessionRevocations::class);

        $this->assertTrue($revocations->isRevoked($sessionId));
        $this->assertStringNotContainsString($sessionId, $revocations->keyFor($sessionId));
        $this->assertNull(Cache::get('session-revoked:'.$sessionId));
    }

    /**
     * A suspended account's session cannot be resurrected either.
     *
     * `EnsureAccountActive` already ended the session on the spot when it refused; that refusal now
     * revokes the id too. Without it, «you are suspended» lasts until a request that was already in
     * flight finishes and hands the account back its session.
     */
    public function test_a_suspended_accounts_session_cannot_be_resurrected(): void
    {
        $sessionId = $this->signIn();
        $payload = $this->readPayload($sessionId);

        User::where('email', 'race@t.test')->update(['disabled_at' => now()]);

        $this->browser($sessionId)->getJson('/api/v1/auth/me')->assertForbidden();

        $this->writePayloadBack($sessionId, $payload);
        $this->browser($sessionId)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /**
     * "Sign out my other devices" retires THIS browser's previous id as well.
     *
     * `regenerate()` deliberately does not destroy the session it leaves behind, so before this the
     * old id kept working for the rest of its lifetime — at the one moment a person is explicitly
     * asking for old access to stop.
     */
    public function test_signing_out_other_devices_retires_the_previous_id(): void
    {
        $old = $this->signIn();

        $this->browser($old)
            ->deleteJson('/api/v1/me/sessions/others', ['current_password' => 'secret123'])
            ->assertOk();

        $new = $this->app['session']->getId();
        $this->assertNotSame($old, $new);

        $this->browser($new)->getJson('/api/v1/auth/me')->assertOk();
        $this->browser($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** A caller with no revoked session is left alone — including the ones with no session at all. */
    public function test_a_request_without_a_revoked_session_is_left_alone(): void
    {
        $sessionId = $this->signIn();
        $this->browser($sessionId)->postJson('/api/v1/auth/logout')->assertOk();

        // A guest: 401 because nobody is signed in, not because a session was revoked.
        $this->browser(null)->getJson('/api/v1/auth/me')->assertUnauthorized();
        // And an endpoint that never needed one still answers.
        $this->browser(null)->getJson('/api/v1/health')->assertSuccessful();
    }
}
