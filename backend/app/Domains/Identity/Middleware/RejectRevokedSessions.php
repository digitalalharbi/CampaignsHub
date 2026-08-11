<?php

declare(strict_types=1);

namespace App\Domains\Identity\Middleware;

use App\Domains\Identity\Support\SessionRevocations;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACCESS-EXIT-003 — a session id that was signed out is refused, however it came back.
 *
 * Sign-out deletes the session, and that was measured to work. What it cannot do is stop a request
 * that loaded the authenticated payload BEFORE the sign-out from writing it back afterwards: the
 * store receives a key it had deleted, holding the bytes it used to hold, and the cookie in the
 * browser is a working credential again. Proven byte for byte over HTTP — restore the 287 bytes and
 * `/auth/me` answers 200 with the same jar that answered 401 a moment earlier.
 *
 * So the store cannot be the authority on whether a session is signed in. This is: the id is checked
 * against a revocation marker that lives outside the session, on every request that carries one.
 *
 * ## Why here, and why this shape
 *
 * Appended to the API group, so it runs inside Sanctum's stateful pipeline — after `StartSession`
 * has resolved the id from the cookie, and before any route can act on it.
 *
 * A revoked session is not merely refused, it is destroyed again on the way out: the resurrected
 * payload is flushed and the id migrated, so the same bytes are not sitting there waiting for the
 * next request. The refusal is a plain 401 returned through the pipeline rather than a thrown
 * `AuthenticationException`, because the response has to travel back out through
 * `AddQueuedCookiesToResponse` for the replacement cookie to reach the browser at all.
 *
 * ## What this deliberately is not
 *
 * Not a lock, not a queue, and not a global sign-out. Concurrent dashboard requests are not
 * serialised — they are simply answered 401 if they carry an id somebody signed out, which is what
 * they should have been answered in the first place. Nothing here knows which user a session
 * belonged to, so a second device holding a different id is untouched by construction.
 *
 * Guests, token clients, webhooks and public report links never reach the check: no session, nothing
 * to revoke.
 */
final class RejectRevokedSessions
{
    public function __construct(private readonly SessionRevocations $revocations) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        if (! $session->isStarted() || ! $this->revocations->isRevoked($session->getId())) {
            return $next($request);
        }

        /*
         * Take the resurrection back down.
         *
         * `logout()` clears the guard's own key, `flush()` removes whatever else the late write
         * restored, and `invalidate()` destroys the id and issues a fresh one — so the next request
         * from this browser is an ordinary guest rather than another rejected zombie.
         */
        Auth::guard('web')->logout();
        $session->flush();
        $session->invalidate();
        $session->regenerateToken();

        return ApiResponse::error(__('api.unauthenticated'), status: 401);
    }
}
