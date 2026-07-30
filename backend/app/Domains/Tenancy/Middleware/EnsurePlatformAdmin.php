<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The gate on the platform owner's console (ADR 0002, ADMIN-001).
 *
 * Deliberately NOT `portal:admin`. Every other portal is entered through a membership, which names a
 * tenant — and the owner belongs to no tenant. Granting them a membership to reach `/admin` would put
 * them inside one of the workspaces they administer, and would make "which tenant is this request
 * for?" answerable when it must not be.
 *
 * So the key is the `is_platform_admin` column and nothing else: not a role, not a permission, not an
 * account type. Roles and permissions are tenant-scoped and a tenant administrator must never be able
 * to grant themselves this by editing their own workspace's roles.
 */
final class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_platform_admin === true, 403);

        return $next($request);
    }
}
