<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Middleware;

use App\Domains\Accounts\Services\AccountEntitlements;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces workspace entitlements at the API — the simplified company menu is not merely hidden in the UI:
 * a company workspace hitting a personal-only capability (clients, requests inbox, team…) is denied here.
 * Runs after `tenant`, so the tenant is resolved. Platform scope (no tenant) is unaffected.
 */
final class EnsureEntitlement
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountEntitlements $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $navKey): Response
    {
        $tenantId = $this->context->tenantId();
        if ($tenantId !== null) {
            $tenant = Tenant::find((string) $tenantId);
            if ($tenant !== null && ! $this->entitlements->allows($tenant, $navKey)) {
                abort(403, 'This capability is not available for your workspace type.');
            }
        }

        return $next($request);
    }
}
