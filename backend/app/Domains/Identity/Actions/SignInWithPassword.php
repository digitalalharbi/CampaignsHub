<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Domains\Tenancy\Support\ReviewAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The password sign-in, in ONE place — shared by `/auth/login` and by linking a Google account in place (LOGIN-004).
 *
 * Linking in place must prove ownership with exactly the proof the password form accepts, so it cannot have its own copy of
 * these checks: a second copy is how a suspended account, a wrong portal or a session fixation gets past one door while the
 * other holds. Everything the form does is here, in the same order — the origin must hold a session, the password is checked,
 * the account must be active, the chosen portal must be held — and only then does a session open and regenerate.
 *
 * `$beforeSession` runs after every refusal has had its say and immediately before the session opens, which is where a link
 * between a provider account and this user may be written: a refused sign-in then leaves nothing behind.
 */
final class SignInWithPassword
{
    public function __construct(private readonly PortalResolver $portals) {}

    public function execute(Request $request, string $email, string $password, bool $remember, ?string $portal, ?Closure $beforeSession = null): User
    {
        $this->assertCanOpenASession($request);

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return $this->openSession($request, $user, $remember, $portal, $beforeSession);
    }

    /**
     * Everything that follows a proof of identity, whatever the proof was: the account must be active, the chosen portal must
     * be held, and only then does a session open and regenerate.
     *
     * Linking a Google account in place proves ownership by password (`execute`) or by a mailed link, and both end here — one
     * set of refusals, one session regeneration, so neither proof can open a door the other would keep shut.
     */
    public function openSession(Request $request, User $user, bool $remember, ?string $portal, ?Closure $beforeSession = null): User
    {
        $this->assertCanOpenASession($request);
        $this->assertActive($user);
        $this->assertHoldsRequestedPortal($portal, $user);

        if ($beforeSession !== null) {
            $beforeSession($user);
        }

        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * AUTH-NONSTATEFUL-ORIGIN — an origin that cannot hold a session is REFUSED, not a 500.
     *
     * Sanctum treats an Origin outside `SANCTUM_STATEFUL_DOMAINS` as non-stateful, so the session middleware never runs and
     * `session()->regenerate()` would throw «Session store not set on request». FIRST, before the password is checked: refusing
     * after would do the hashing work for an origin that can never be answered, a timing oracle handed to the caller who should
     * have been turned away at the door.
     */
    private function assertCanOpenASession(Request $request): void
    {
        abort_if(
            ! $request->hasSession(),
            403,
            'This origin cannot open a session. Sign in from an address this installation serves.',
        );
    }

    /** A suspended/disabled account (or suspended workspace) can never sign in or mint a token. Generic message. */
    public function assertActive(User $user): void
    {
        // ADR 0002: suspension follows the memberships, not the legacy column — see AccountSuspension.
        $suspended = $user->disabled_at !== null
            || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user));
        abort_if($suspended, 403, __('auth.unavailable'));

        // SALLA-REVIEW-001 — a reviewer whose access has ended is told so, not that «the account is not available».
        abort_if(! $user->is_platform_admin && ReviewAccess::endedFor($user), 403, ReviewAccess::ENDED_MESSAGE);
    }

    /**
     * Refuse the sign-in itself when the chosen portal is not one this account holds (LOGIN-003).
     *
     * BEFORE `Auth::login`, so no session is created and nothing is navigated; the form answers exactly as it does for bad
     * credentials. A 403 rather than a validation error, because the credentials were correct and saying otherwise would be
     * false. The payload names where this account SHOULD go.
     */
    private function assertHoldsRequestedPortal(?string $portal, User $user): void
    {
        $requested = Portal::tryFrom((string) $portal);

        if ($requested === null || $this->portals->holds($user, $requested)) {
            return;
        }

        abort(response()->json([
            'success' => false,
            'message' => __('auth.portal_mismatch'),
            'data' => null,
            'errors' => null,
            'meta' => [
                'portal_mismatch' => true,
                'requested_portal' => $requested->value,
                'destination' => $this->portals->landingPathFor($user),
            ],
        ], 403));
    }
}
