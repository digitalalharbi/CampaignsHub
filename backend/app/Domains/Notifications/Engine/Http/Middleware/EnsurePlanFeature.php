<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Middleware;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Enums\EntitlementMode;
use App\Domains\Tenancy\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `plan.feature:<entitlement key>` — the API half of spec §1 (the scheduled-job half is in the sender and scheduler).
 *
 * A safe read (GET/HEAD) passes while the key is held in any mode except `off`: view-only means the data and the
 * settings stay visible. Any write needs a usable key. The refusal is RETURNED (an `abort()` with a response renders as a
 * 500 in this application — see `EnsureEntitlement`) and carries `upgrade: true` for the «ترقية» button.
 */
final class EnsurePlanFeature
{
    public function __construct(private readonly TenantContext $tenants, private readonly EntitlementGate $gate) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $tenantId = $this->tenants->tenantId();
        $entitlement = EntitlementKey::from($key);

        if ($tenantId !== null) {
            $state = $this->gate->state((string) $tenantId, $entitlement);
            $read = $request->isMethodSafe();

            if (($read && $state->mode === EntitlementMode::Off) || (! $read && ! $state->canEdit())) {
                return response()->json([
                    'success' => false,
                    'message' => __('notifications_v2.upgrade'),
                    'data' => null,
                    'errors' => ['entitlement' => [$entitlement->value]],
                    'meta' => ['entitlement' => $state->toArray(), 'upgrade' => true, 'upgrade_path' => '/app/subscriptions'],
                ], 403);
            }
        }

        return $next($request);
    }
}
