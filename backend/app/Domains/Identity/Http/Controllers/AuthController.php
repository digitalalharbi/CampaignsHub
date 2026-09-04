<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Identity\Services\SignInMethodResolver;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Identity\Support\SessionRevocations;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SPA cookie-session authentication (Sanctum stateful). See ADR 0001.
 * Personal Access Tokens are issued only via {@see issueToken()} for non-browser clients.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly PortalResolver $portals,
        private readonly SignInMethodResolver $methods,
    ) {}

    /**
     * LOGIN-UNIFIED-001 — which form the single sign-in page should show for this identifier.
     *
     * There is one door now, `/login`, and the visitor is never asked to pick a portal. They type an
     * email or a phone; this says whether that identifier signs in with a password or with a one-time
     * code, and the page renders the matching step. Everything after — which portal, whether the
     * account is suspended, where to land — is still decided by {@see login()} and `PortalResolver`.
     *
     * Deliberately uninformative about existence: see `SignInMethodResolver` for why an unknown email
     * answers `password` rather than `code`.
     */
    public function method(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
        ]);

        return ApiResponse::success($this->methods->resolve((string) $data['identifier']));
    }

    /*
     * `register` used to live here and no longer does (SIGNUP-002).
     *
     * It created a tenant, a workspace, a user and a membership, then opened a session — so signing
     * up and being granted an operating account were the same event, and there was no point at which
     * verification, approval or payment could be required. Applying is now handled by
     * `Accounts\Http\Controllers\RegistrationController`, which grants nothing; this class is left
     * with what it was always about, which is authenticating an account that already exists.
     */

    public function login(LoginRequest $request): JsonResponse
    {
        $this->assertCanOpenASession($request);

        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }
        $this->assertActive($user);
        $this->assertHoldsRequestedPortal($request, $user);

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            __('auth.signed_in'),
        );
    }

    /**
     * AUTH-NONSTATEFUL-ORIGIN — an origin that cannot hold a session is REFUSED, not a 500.
     *
     * Sanctum treats an Origin outside `SANCTUM_STATEFUL_DOMAINS` as non-stateful, so the session
     * middleware never runs; the `session()->regenerate()` below then threw «Session store not set
     * on request» and the caller got a 500. Reproduced deliberately against a local server: the same
     * credentials answered 200 from a listed origin and 500 from an unlisted one.
     *
     * A 500 is the wrong answer to a request that should be refused. It says the server broke rather
     * than «not from here», it writes a stack trace for every attempt — so any client on an unlisted
     * origin, a second front end or a staging host mid-cutover, can fill the log in a loop — and it
     * tells an prober that something unusual happened here rather than nothing.
     *
     * FIRST, before the password is checked. Refusing after would do the hashing work for an origin
     * that can never be answered, and would make this path measurably slower for a real account than
     * for an unknown one — a timing oracle handed to exactly the caller who should have been turned
     * away at the door. It also says nothing about the credentials, because it has not looked.
     */
    private function assertCanOpenASession(Request $request): void
    {
        abort_if(
            ! $request->hasSession(),
            403,
            'This origin cannot open a session. Sign in from an address this installation serves.',
        );
    }

    /**
     * Refuse the sign-in itself when the chosen portal is not one this account holds (LOGIN-003).
     *
     * This runs BEFORE `Auth::login`, and that placement is the whole point. The check used to
     * happen after the session existed: you were signed in, moved to a portal, and only then shown a
     * "not available" page — a wrong-portal choice behaved nothing like a wrong password, even
     * though it is the same kind of mistake and belongs in the same place. Now no session is created
     * and nothing is navigated; the form answers, exactly as it does for bad credentials.
     *
     * A 403 rather than a validation error, because the credentials were correct and saying
     * otherwise would be false. The payload names where this account SHOULD go, so the form can
     * offer a way through instead of leaving the person to guess.
     */
    private function assertHoldsRequestedPortal(LoginRequest $request, User $user): void
    {
        $requested = Portal::tryFrom((string) $request->string('portal'));

        if ($requested === null || $this->portals->holds($user, $requested)) {
            return;
        }

        abort(response()->json([
            'success' => false,
            'message' => __('auth.portal_mismatch'),
            'data' => null,
            'errors' => null,
            'meta' => [
                // What the interface needs to say something useful: which portal was refused, and
                // the one this account actually belongs to.
                'portal_mismatch' => true,
                'requested_portal' => $requested->value,
                'destination' => $this->portals->landingPathFor($user),
            ],
        ], 403));
    }

    /** A suspended/disabled account (or suspended workspace) can never sign in or mint a token. Generic message. */
    private function assertActive(User $user): void
    {
        // ADR 0002: suspension follows the memberships, not the legacy column — see AccountSuspension.
        $suspended = $user->disabled_at !== null
            || (! $user->is_platform_admin && AccountSuspension::everyWorkspaceSuspendedFor($user));
        abort_if($suspended, 403, __('auth.unavailable'));
    }

    /** Current authenticated user — used by the SPA to restore its session on load. */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['user' => new UserResource($request->user())],
            __('auth.current_user'),
        );
    }

    /**
     * Sign out — and record that this session id is finished (ACCESS-EXIT-003).
     *
     * The three lines below were never wrong: the session really is destroyed, measured over HTTP
     * against Redis (`EXISTS` 1 → 0, `TTL` -2). What they cannot do is bind a request that loaded
     * this session BEFORE the sign-out, and that request writes the authenticated payload back under
     * the same id when it finishes — restoring the exact bytes was proven to make the same cookie
     * answer 200 again.
     *
     * So the destruction is paired with a marker that lives outside the session, where a late write
     * cannot reach it. Recorded FIRST, before `invalidate()` mints a new id, because after that call
     * `$request->session()->getId()` names the fresh session and not the one being ended.
     */
    public function logout(Request $request, SessionRevocations $revocations): JsonResponse
    {
        $revocations->revoke($request->session()->getId());

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, __('auth.signed_out'));
    }

    /**
     * Password-reset request — MAIL-009.
     *
     * Responds with the SAME generic message whether or not the account exists. That is not
     * politeness: an unauthenticated form that answers differently for a known address is a directory
     * of who has an account here, and it is the cheapest one a product can offer.
     *
     * The delivery state is deliberately NOT in the response. Whether a message was sent, is awaiting
     * credentials, or failed is exactly the fact that would distinguish a real address from an unknown
     * one — so it lives in `mail_deliveries`, where an operator can read it and a stranger cannot.
     *
     * What used to be here was a log line and a TODO: no token was issued and no reset endpoint
     * existed, so «check your email» pointed at nothing and an account with a lost password was lost.
     */
    public function forgotPassword(Request $request, PasswordResetService $resets): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $resets->request((string) $data['email']);

        return ApiResponse::success(null, __('api.password_reset_sent'));
    }

    /**
     * Consume a reset link and set the new password.
     *
     * Public — the person using it cannot sign in by definition, which is the whole point. The token
     * is the authorisation, and `PasswordResetService` answers every failure identically so a stale
     * link cannot be used to probe for open requests.
     *
     * No session is opened on success. Signing somebody in because they proved control of an email
     * inbox skips the password they have just chosen, and the next screen should be the one that
     * checks it.
     */
    public function resetPassword(Request $request, PasswordResetService $resets): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $resets->reset((string) $data['email'], (string) $data['token'], (string) $data['password']);

        return ApiResponse::success(null, __('api.password_reset_done'));
    }

    /**
     * Issue a Personal Access Token for NON-browser API clients (mobile, integrations).
     * Browsers use the cookie session above and never receive a token.
     */
    public function issueToken(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }
        $this->assertActive($user);

        $token = $user->createToken((string) $request->input('device_name', 'api'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
            __('auth.token_issued'),
        );
    }
}
