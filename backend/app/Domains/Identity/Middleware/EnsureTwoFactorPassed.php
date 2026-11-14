<?php

declare(strict_types=1);

namespace App\Domains\Identity\Middleware;

use App\Domains\Identity\Actions\EstablishSession;
use App\Domains\Identity\Services\TwoFactorMethods;
use App\Models\User;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TWO-STEP-001 — deny by default: a browser session for a person with a second step on must have passed it.
 *
 * {@see EstablishSession} is the one place a session opens and it never opens one before the second step. This is the backstop
 * for everything that is not that place: a session opened before this check existed (the Owner's own, among them), or any door
 * a future change adds without going through it. Such a session is ended and answered 401 «يلزم التحقق بخطوتين».
 *
 * A remember-me cookie restoring the session is accepted: that cookie is only ever set by a sign-in that completed the second
 * step, because turning the first method on — and the migration that introduced this check — retire every older one.
 */
final class EnsureTwoFactorPassed
{
    public function __construct(private readonly TwoFactorMethods $methods) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return $next($request);
        }

        $guard = Auth::guard('web');
        $user = $guard->user();

        if (! $user instanceof User || ! $this->methods->required($user)) {
            return $next($request);
        }

        $session = $request->session();
        $passed = $session->get(EstablishSession::PASSED);

        if ($passed !== null && (string) $passed === (string) $user->getKey()) {
            return $next($request);
        }

        /** @var SessionGuard $guard */
        if ($guard->viaRemember()) {
            $session->put(EstablishSession::PASSED, $user->getKey());

            return $next($request);
        }

        $guard->logoutCurrentDevice();
        $session->invalidate();
        $session->regenerateToken();

        return ApiResponse::error((string) __('auth.two_factor_required'), null, ['two_factor_required' => true], 401);
    }
}
