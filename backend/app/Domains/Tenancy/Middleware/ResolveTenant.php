<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Middleware;

use App\Domains\Tenancy\Context\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant for the request from the AUTHENTICATED USER only.
 * The tenant id is never taken from request input, headers, or route params.
 */
final class ResolveTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            if ($user->tenant_id !== null) {
                $this->context->setTenantId($user->tenant_id);
            } elseif ($user->is_platform_admin) {
                // Platform staff operate across tenants unless they select one explicitly.
                $this->context->enterPlatformScope();
            }
        }

        return $next($request);
    }
}
