<?php

declare(strict_types=1);

namespace App\Domains\Projects\Middleware;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active project from the ROUTE (never from body). Verifies it belongs to the current
 * tenant (the tenant global scope makes this fail-closed) and that the user may access it, then puts
 * it in ProjectContext. Returns 404 (not 403) for a missing/other-tenant project to avoid leaking
 * existence.
 */
final class ResolveProject
{
    public function __construct(private readonly ProjectContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $projectId = (string) $request->route('project');

        // Tenant global scope is already active (ResolveTenant runs first), so a cross-tenant id
        // simply returns null here — fail-closed.
        $project = Project::find($projectId);
        abort_if($project === null, 404, 'Project not found.');

        // Agency staff with projects.view may access any project in their tenant. (Client-portal
        // membership checks are layered on client routes.)
        abort_unless($request->user()?->hasPermission('projects.view'), 403);

        $this->context->setProjectId((string) $project->id);

        return $next($request);
    }
}
