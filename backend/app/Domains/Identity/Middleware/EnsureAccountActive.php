<?php

declare(strict_types=1);

namespace App\Domains\Identity\Middleware;

use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
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
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolve the user from whichever guard authenticated the request (web session for the SPA, sanctum
        // for token/tests). This middleware is appended to the api group, so the default guard may be unset.
        $user = $request->user() ?? Auth::guard('sanctum')->user() ?? Auth::guard('web')->user();
        if ($user === null) {
            return $next($request); // guest — nothing to enforce
        }

        $disabled = $user->disabled_at !== null;
        $tenantSuspended = false;
        if ($user->tenant_id !== null) {
            $status = Tenant::whereKey($user->tenant_id)->value('status');
            $tenantSuspended = in_array($status, ['suspended', 'inactive'], true);
        }

        if ($disabled || $tenantSuspended) {
            $this->audit->log('auth.blocked_suspended', 'user', (string) $user->id,
                after: ['reason' => $disabled ? 'user_disabled' : 'workspace_suspended']);

            // Revoke the current session immediately (stateful requests) so it cannot be reused.
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
            // Revoke any Sanctum PAT used for this request.
            $token = $user->currentAccessToken();
            if ($token !== null && method_exists($token, 'delete')) {
                $token->delete();
            }

            abort(403, 'Your account is not available. Please contact support.');
        }

        return $next($request);
    }
}
