<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Actions\EstablishSession;
use App\Domains\Identity\Actions\SignInWithPassword;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\TwoFactorMethods;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TWO-STEP-001 — answering the second step at sign-in.
 *
 * Public, because the person answering it is not signed in — that is the point. The authorisation is the PENDING state this
 * browser's session holds after a correct first step ({@see EstablishSession}); without it every endpoint here answers 410 and
 * the sign-in starts again.
 *
 * Three limits stack. The route's `otp-check` throttle bounds a single address; five wrong answers end the pending step, so a
 * stolen password buys five guesses and then another full sign-in; and a per-account limit (ten per fifteen minutes) bounds
 * those restarts, so signing in again and again does not reset the count. Every refusal is the same sentence.
 */
final class TwoFactorChallengeController extends Controller
{
    public const MAX_FAILURES = 5;

    private const ACCOUNT_LIMIT = 10;

    private const ACCOUNT_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly TwoFactorMethods $methods,
        private readonly EstablishSession $session,
        private readonly PortalResolver $portals,
        private readonly AuditLogger $audit,
        private readonly SignInWithPassword $signIn,
    ) {}

    /** GET /auth/two-factor — what the pending step can be answered with. */
    public function show(Request $request): JsonResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return $this->gone();
        }

        return ApiResponse::success(['two_factor' => $this->methods->challengeFor($user)], (string) __('auth.two_factor_required'));
    }

    /** POST /auth/two-factor/send — a code by email or SMS. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(['email', 'sms'])]]);

        $user = $this->pendingUser($request);
        if ($user === null) {
            return $this->gone();
        }

        if (! in_array($data['method'], $this->methods->active($user), true)) {
            throw ValidationException::withMessages(['method' => [__('auth.two_factor_method_inactive')]]);
        }

        $sent = $this->methods->sendCode($user, $data['method'], TwoFactorMethods::SIGN_IN_PURPOSE);

        $pending = EstablishSession::pending($request);
        $request->session()->put(EstablishSession::PENDING, array_merge((array) $pending, [
            'verification_id' => $sent['verification_id'],
            'verification_method' => $data['method'],
        ]));

        return ApiResponse::success([
            'delivery_status' => $sent['delivery_status'],
            'resend_after' => $sent['resend_after'],
            'dev_code' => $sent['dev_code'],
        ], (string) __('auth.code_sent'));
    }

    /** POST /auth/two-factor/verify — the answer. Right: the session opens. Wrong: the same sentence every time. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['totp', 'email', 'sms', 'recovery'])],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $pending = EstablishSession::pending($request);
        $user = $this->pendingUser($request);
        if ($pending === null || $user === null) {
            return $this->gone();
        }

        $limiterKey = 'two-factor-challenge:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($limiterKey, self::ACCOUNT_LIMIT)) {
            return ApiResponse::error((string) __('auth.two_factor_locked'), null, ['state' => 'locked'], 429);
        }

        $verificationId = ($pending['verification_method'] ?? null) === $data['method'] ? ($pending['verification_id'] ?? null) : null;

        if (! $this->methods->verify($user, $data['method'], (string) $data['code'], is_string($verificationId) ? $verificationId : null)) {
            RateLimiter::hit($limiterKey, self::ACCOUNT_WINDOW_SECONDS);
            $failures = (int) ($pending['failures'] ?? 0) + 1;

            $this->audit->log(action: 'security.two_factor.challenge_failed', entityType: 'user', entityId: (string) $user->uuid,
                after: ['method' => $data['method'], 'failures' => $failures], userId: (int) $user->getKey());

            if ($failures >= self::MAX_FAILURES) {
                $request->session()->forget(EstablishSession::PENDING);

                return $this->gone();
            }

            $request->session()->put(EstablishSession::PENDING, array_merge($pending, ['failures' => $failures]));

            throw ValidationException::withMessages(['code' => [__('auth.code_incorrect')]]);
        }

        RateLimiter::clear($limiterKey);
        // The same refusals the first step applied, again: an account suspended in the last ten minutes does not get in.
        $this->signIn->assertActive($user);
        $this->session->complete($request, $user, (bool) ($pending['remember'] ?? false));

        $this->audit->log(action: 'security.two_factor.challenge_passed', entityType: 'user', entityId: (string) $user->uuid,
            after: ['method' => $data['method']], userId: (int) $user->getKey());

        return ApiResponse::success([
            'user' => new UserResource($user),
            // The same answer the password form and the Google callback get, by the same method.
            'destination' => $this->portals->postAuthDestination(
                $user,
                Portal::tryFrom((string) ($pending['portal'] ?? '')),
                is_string($pending['redirect'] ?? null) ? $pending['redirect'] : null,
            ),
        ], (string) __('auth.signed_in'));
    }

    /** DELETE /auth/two-factor — «رجوع»: forget the pending step. */
    public function cancel(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            $request->session()->forget(EstablishSession::PENDING);
        }

        return ApiResponse::success(null);
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = EstablishSession::pending($request);
        if ($pending === null) {
            return null;
        }

        $user = User::query()->find($pending['user_id'] ?? null);

        // Suspended in the ten minutes since the password was checked: the challenge ends with it.
        if ($user === null || $user->disabled_at !== null || ! $this->methods->required($user)) {
            $request->session()->forget(EstablishSession::PENDING);

            return null;
        }

        return $user;
    }

    private function gone(): JsonResponse
    {
        return ApiResponse::error((string) __('auth.two_factor_expired'), null, ['state' => 'expired'], 410);
    }
}
