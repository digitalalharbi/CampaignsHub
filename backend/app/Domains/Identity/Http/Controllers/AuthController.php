<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\SignInWithPassword;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\PasswordResetService;
use App\Domains\Identity\Support\SessionRevocations;
use App\Domains\Tenancy\Support\ReviewAccess;
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
        private readonly SignInWithPassword $signIn,
    ) {}

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
        // LOGIN-004 — the sign-in itself lives in one place, shared with linking a Google account in place.
        $user = $this->signIn->execute(
            $request,
            (string) $request->string('email'),
            (string) $request->string('password'),
            (bool) $request->boolean('remember'),
            (string) $request->string('portal'),
        );

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            __('auth.signed_in'),
        );
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
        $this->signIn->assertActive($user);
        // SALLA-REVIEW-001 — a review account is never issued an API token (this route has no tenant context to restrict it by).
        abort_if(ReviewAccess::isReviewer($user), 403, 'API tokens are not available in the Salla review account.');

        $token = $user->createToken((string) $request->input('device_name', 'api'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
            __('auth.token_issued'),
        );
    }
}
