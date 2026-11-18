<?php

declare(strict_types=1);

namespace App\Domains\Identity\Actions;

use App\Domains\Identity\Services\TwoFactorMethods;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * TWO-STEP-001 — the ONE place a browser session is opened for a person, whatever proved who they are.
 *
 * Password, Google, a phone code, a Salla link, an accepted invitation, a provisioned registration, a portal code: each used to
 * call `Auth::login` itself, and none of them asked whether the person had turned on a second step. So turning it on changed
 * nothing at all — the flag was stored and never read.
 *
 * Every door now ends here. A person with a second step on gets a PENDING state in this session and nothing else: no login, no
 * `Login` event, no remember-me cookie, no access to any route. Only {@see complete()} — reached after the second step passes —
 * opens the session, and it regenerates the id so a session planted before the challenge cannot be carried past it.
 */
final class EstablishSession
{
    public const PENDING = 'two_factor.pending';

    /** Which user this session passed the second step for. Read by `EnsureTwoFactorPassed` on every request. */
    public const PASSED = 'two_factor.passed_for';

    public const PENDING_TTL_SECONDS = 600;

    public function __construct(private readonly TwoFactorMethods $methods) {}

    /**
     * Open the session, or hold it at the second step.
     *
     * @param  array{portal?: ?string, redirect?: ?string}  $context  where the sign-in was headed, kept for after the challenge
     * @return array<string, mixed>|null null when the session is open; the challenge to answer when it is not
     */
    public function open(Request $request, User $user, bool $remember = false, array $context = []): ?array
    {
        if ($this->methods->required($user)) {
            if ($request->hasSession()) {
                // Whoever this browser was signed in as before, it is nobody until the second step passes.
                $guard = Auth::guard('web');
                if ($guard instanceof SessionGuard && $guard->check()) {
                    $guard->logoutCurrentDevice();
                }
                $request->session()->regenerate();
                $request->session()->forget(self::PASSED);
                $request->session()->put(self::PENDING, [
                    'user_id' => $user->getKey(),
                    'remember' => $remember,
                    'portal' => $context['portal'] ?? null,
                    'redirect' => $context['redirect'] ?? null,
                    'expires_at' => now()->getTimestamp() + self::PENDING_TTL_SECONDS,
                    'failures' => 0,
                    'verification_id' => null,
                ]);
            }

            return $this->methods->challengeFor($user);
        }

        $this->login($request, $user, $remember);

        return null;
    }

    /** The second step passed: now, and only now, the session opens. */
    public function complete(Request $request, User $user, bool $remember): void
    {
        $this->login($request, $user, $remember);
    }

    /**
     * The pending state, if this session holds a live one.
     *
     * @return array<string, mixed>|null
     */
    public static function pending(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(self::PENDING);

        if (! is_array($pending) || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $pending;
    }

    /** The answer a JSON sign-in gives while the second step is outstanding. Status 200: the credentials were right. */
    public static function challengeResponse(array $challenge): JsonResponse
    {
        return ApiResponse::success(
            ['user' => null, 'two_factor' => $challenge],
            (string) __('auth.two_factor_required'),
            ['two_factor_required' => true],
        );
    }

    private function login(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);

        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->forget(self::PENDING);
            $request->session()->put(self::PASSED, $user->getKey());
        }
    }
}
