<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * ACCESS-EXIT-003 — the record that one session id was signed out, kept OUTSIDE that session.
 *
 * It has to live outside. Sign-out is defeated by a concurrent request writing the pre-logout
 * payload back under the same id, and that write replaces the payload wholesale — so a "revoked"
 * flag stored inside the session would be replaced along with everything else. The only marker a
 * late write cannot overwrite is one it does not touch.
 *
 * ## Scope: one session id, and nothing else
 *
 * Not the user, not the device, not the workspace. Signing out on a laptop must not end the session
 * on the phone, so nothing here is keyed by user id — a revocation names exactly the credential the
 * person just gave up and says nothing about any other.
 *
 * ## The id is never stored
 *
 * A revocation list keyed by raw session ids is a list of live credentials for as long as its
 * entries are unexpired, readable by anything that can read the cache. Every entry here is a
 * SHA-256 of the id and is only ever compared against another SHA-256, so it answers "was this
 * one revoked?" without being able to answer "what were they?".
 *
 * ## Lifetime
 *
 * A revoked id stops being dangerous when its payload can no longer exist. A late write re-creates
 * the session with a fresh `session.lifetime`, so the marker must outlive that by construction:
 * one full lifetime measured from the sign-out, plus a margin for a write that lands late enough to
 * push the expiry a few seconds further out. Beyond that the cookie is worthless anyway, and
 * keeping the marker longer would only grow a table of dead ids.
 *
 * The markers live in the cache, so `php artisan cache:clear` drops them. That is survivable and
 * worth stating plainly: it can only matter for a session signed out in the seconds around the
 * clear AND with a request still in flight from before the sign-out. Everything else is already
 * destroyed by `invalidate()`. Deployments that want the window closed should clear the cache
 * before traffic reaches the release, not during.
 */
final class SessionRevocations
{
    private const PREFIX = 'session-revoked:';

    /** Seconds added on top of a full session lifetime, covering a write that lands after logout. */
    private const MARGIN_SECONDS = 300;

    public function __construct(private readonly Cache $cache) {}

    public function revoke(string $sessionId): void
    {
        $this->cache->put($this->keyFor($sessionId), true, $this->ttlSeconds());
    }

    public function isRevoked(string $sessionId): bool
    {
        return $this->cache->has($this->keyFor($sessionId));
    }

    /** Public so a test can assert the raw id does not appear in it. */
    public function keyFor(string $sessionId): string
    {
        return self::PREFIX.hash('sha256', $sessionId);
    }

    private function ttlSeconds(): int
    {
        return ((int) config('session.lifetime', 120)) * 60 + self::MARGIN_SECONDS;
    }
}
