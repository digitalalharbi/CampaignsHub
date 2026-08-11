<?php

declare(strict_types=1);

namespace App\Domains\Identity\Middleware;

use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Identity\Support\SessionRevocations;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Denies every authenticated request from a suspended/disabled account — the user is disabled (disabled_at)
 * or their workspace is suspended/inactive. The current session is invalidated (revoked) on the spot, a
 * generic non-revealing message is returned, and the block is audited. Guests are untouched. Re-activation
 * is only possible by clearing disabled_at / restoring the tenant status, which is an authorized action.
 */
final class EnsureAccountActive
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRevocations $revocations,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolve the user from whichever guard authenticated the request (web session for the SPA, sanctum
        // for token/tests). This middleware is appended to the api group, so the default guard may be unset.
        $user = $request->user() ?? Auth::guard('sanctum')->user() ?? Auth::guard('web')->user();
        if (! $user instanceof User) {
            return $next($request); // guest — nothing to enforce
        }

        $disabled = $user->disabled_at !== null;

        /*
         * ADR 0002: suspension is a property of the WORKSPACE a person is working in, not of the
         * person. Reading it off `users.tenant_id` meant one suspended workspace locked them out of
         * every other workspace they legitimately belong to. Blocked only when every workspace they
         * can reach is suspended — otherwise the switcher will land them in one that still works.
         */
        $tenantSuspended = ! $user->is_platform_admin
            && AccountSuspension::everyWorkspaceSuspendedFor($user);

        if ($disabled || $tenantSuspended) {
            $this->audit->log('auth.blocked_suspended', 'user', (string) $user->id,
                after: ['reason' => $disabled ? 'user_disabled' : 'workspace_suspended']);

            /*
             * Revoke the current session immediately (stateful requests) so it cannot be reused.
             *
             * The marker is recorded BEFORE `invalidate()`, for the same reason as in
             * `AuthController::logout()`: destroying the session is not enough on its own, because a
             * request that loaded it before this refusal writes the authenticated payload back under
             * the same id when it finishes (ACCESS-EXIT-003). A suspended account is precisely the
             * case where "signed out, mostly" is not an acceptable answer.
             */
            if ($request->hasSession()) {
                $this->revocations->revoke($request->session()->getId());
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            // Revoke any Sanctum PAT used for this request.
            $token = $user->currentAccessToken();
            if ($token !== null && method_exists($token, 'delete')) {
                $token->delete();
            }

            abort(403, __('auth.unavailable'));
        }

        return $next($request);
    }
}
