<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Resolves which tenant owns an incoming public-portal request — WITHOUT a fragile env UUID:
 *   1. exact host match on tenants.portal_domain (must be portal_enabled), else
 *   2. the single tenant flagged is_default_portal (must be portal_enabled), else
 *   3. env REQUESTS_PORTAL_TENANT_ID — DEV/TEST ONLY (never trusted in production), else
 *   4. null → the intake fails closed (no arbitrary Tenant::first()).
 */
final class PortalTenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $host = strtolower($request->getHost());

        $byDomain = Tenant::query()->where('portal_enabled', true)
            ->whereRaw('lower(portal_domain) = ?', [$host])->first();
        if ($byDomain !== null) {
            return $byDomain;
        }

        $default = Tenant::query()->where('portal_enabled', true)->where('is_default_portal', true)->first();
        if ($default !== null) {
            return $default;
        }

        if (! app()->environment('production')) {
            $configured = config('requests.portal_tenant_id');
            if ($configured !== null) {
                return Tenant::query()->whereKey($configured)->first();
            }
        }

        return null; // fail closed
    }
}
