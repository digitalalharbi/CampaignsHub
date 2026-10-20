<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Middleware;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CAP-REGISTRY-001 — `module:attribution_intelligence` refuses a route whose module is off.
 *
 * Runs after `tenant`. The project is taken from the route's `project` parameter when it has one, and the
 * answer is for that project's client and project; a route with none is answered for the whole workspace.
 * The refusal names the module and the reason, so the page can say what to do rather than show a blank.
 */
final class EnsureCapability
{
    public function __construct(private readonly Capabilities $capabilities) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(Capabilities::exists($module), 500, 'Unknown module.');

        $tenantId = app(TenantContext::class)->tenantId();
        abort_if($tenantId === null, 403, 'No tenant context.');
        $tenant = Tenant::query()->findOrFail($tenantId);

        $projectId = null;
        $clientId = null;
        $param = $request->route('project');
        if ($param !== null) {
            $id = is_object($param) ? (string) $param->getKey() : (string) $param;
            $project = Project::query()->where('tenant_id', $tenantId)->whereKey($id)->first();
            abort_if($project === null, 404);
            $projectId = $id;
            $clientId = (string) $project->client_workspace_id;
        }

        $state = $this->capabilities->resolve($tenant, $clientId, $projectId)[$module];

        if (! $state->enabled) {
            return ApiResponse::error(
                'This module is not available here.',
                ['module' => $module, 'reason' => $state->reason],
                status: 403,
            );
        }

        return $next($request);
    }
}
