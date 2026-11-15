<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\EstablishSession;
use App\Domains\Identity\Services\TwoFactorMethods;
use App\Domains\Identity\Support\UserSessions;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * TWO-STEP-001 — the person's own second-step methods, under «الأمان والجلسات».
 *
 * Self-only: every action reads `$request->user()` and no user id is ever accepted from the client.
 *
 * Turning the FIRST method on signs out every other session and API token (the repository's «sign out everywhere»,
 * {@see UserSessions::revokeAll()}): they were opened without a second step, and leaving them open would make the switch a
 * promise about future sign-ins only. The session that turned it on stays, marked as having passed. Turning methods off asks for
 * the password or a valid code again, at the moment of the change.
 */
final class TwoFactorSettingsController extends Controller
{
    public function __construct(
        private readonly TwoFactorMethods $methods,
        private readonly UserSessions $sessions,
    ) {}

    /** GET /me/two-factor */
    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->methods->status($this->user($request)));
    }

    /** POST /me/two-factor/totp/setup — a secret and its QR address. Not active until a first code confirms it. */
    public function totpSetup(Request $request): JsonResponse
    {
        return ApiResponse::success($this->methods->beginTotp($this->user($request)));
    }

    /** POST /me/two-factor/totp/confirm */
    public function totpConfirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $user = $this->user($request);

        $this->throttleCode($user);
        $codes = $this->methods->confirmTotp($user, (string) $data['code']);

        return $this->activated($request, $user, $codes);
    }

    /** POST /me/two-factor/{method}/start — a code to the verified address or the proved number, to confirm it reaches you. */
    public function channelStart(Request $request, string $method): JsonResponse
    {
        abort_unless(in_array($method, ['email', 'sms'], true), 404);
        $user = $this->user($request);

        $sent = $this->methods->sendCode($user, $method, TwoFactorMethods::ENROL_PURPOSE);
        Cache::put($this->enrolKey($user, $method), $sent['verification_id'], now()->addMinutes(15));

        return ApiResponse::success([
            'delivery_status' => $sent['delivery_status'],
            'resend_after' => $sent['resend_after'],
            'dev_code' => $sent['dev_code'],
        ], (string) __('auth.code_sent'));
    }

    /** POST /me/two-factor/{method}/confirm */
    public function channelConfirm(Request $request, string $method): JsonResponse
    {
        abort_unless(in_array($method, ['email', 'sms'], true), 404);
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $user = $this->user($request);

        $verificationId = Cache::get($this->enrolKey($user, $method));
        if (! is_string($verificationId)) {
            throw ValidationException::withMessages(['code' => [__('auth.code_expired')]]);
        }

        $codes = $this->methods->confirmChannel($user, $method, $verificationId, (string) $data['code']);
        Cache::forget($this->enrolKey($user, $method));

        return $this->activated($request, $user, $codes);
    }

    /** POST /me/two-factor/default */
    public function setDefault(Request $request): JsonResponse
    {
        $data = $request->validate(['method' => ['required', Rule::in(TwoFactorMethods::METHODS)]]);
        $user = $this->user($request);

        $this->methods->setDefault($user, $data['method']);

        return ApiResponse::success($this->methods->status($user->refresh()));
    }

    /** DELETE /me/two-factor/methods/{method} — needs the password or a valid code. The last one turns the second step off. */
    public function removeMethod(Request $request, string $method): JsonResponse
    {
        abort_unless(in_array($method, TwoFactorMethods::METHODS, true), 404);
        $user = $this->user($request);
        $this->assertReconfirmed($request, $user);

        $this->methods->remove($user, $method);

        return ApiResponse::success($this->methods->status($user->refresh()));
    }

    /** POST /me/two-factor/disable — every method off. Needs the password or a valid code. */
    public function disable(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->assertReconfirmed($request, $user);

        $this->methods->disable($user);

        return ApiResponse::success($this->methods->status($user->refresh()), (string) __('auth.two_factor_disabled'));
    }

    /** POST /me/two-factor/recovery-codes — ten new codes, shown once; the old ones stop working. */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($this->methods->required($user), 422, (string) __('auth.two_factor_method_inactive'));
        $this->assertReconfirmed($request, $user);

        return ApiResponse::success(['recovery_codes' => $this->methods->regenerateRecoveryCodes($user)]);
    }

    /** @param list<string>|null $codes */
    private function activated(Request $request, User $user, ?array $codes): JsonResponse
    {
        if ($codes !== null) {
            // First method on: every other session and token was opened without a second step.
            $this->sessions->revokeAll($user, $request->hasSession() ? $request->session() : null);
        }

        if ($request->hasSession()) {
            $request->session()->put(EstablishSession::PASSED, $user->getKey());
        }

        return ApiResponse::success(
            $this->methods->status($user->refresh()) + ['recovery_codes' => $codes],
            (string) __('auth.two_factor_enabled'),
        );
    }

    private function assertReconfirmed(Request $request, User $user): void
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:1024'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $this->throttleCode($user);

        if (! $this->methods->reconfirm($user, $data['password'] ?? null, $data['code'] ?? null)) {
            throw ValidationException::withMessages(['password' => [__('auth.two_factor_reconfirm_failed')]]);
        }
    }

    /** Guessing a code from inside a session is bounded too: ten tries in fifteen minutes per person. */
    private function throttleCode(User $user): void
    {
        $key = 'two-factor-settings:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(429, (string) __('auth.two_factor_locked'));
        }
        RateLimiter::hit($key, 900);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function enrolKey(User $user, string $method): string
    {
        return 'two_factor:enrol:'.$method.':'.$user->getKey();
    }
}
