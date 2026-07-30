<?php

declare(strict_types=1);

namespace App\Domains\Projects\Middleware;

use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
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

        // Tenant global scope is already active (ResolveMembership runs first), so a cross-tenant id
        // simply returns null here — fail-closed.
        $project = Project::find($projectId);
        abort_if($project === null, 404, 'Project not found.');

        $user = $request->user();
        abort_unless($user?->hasPermission('projects.view'), 403);

        // Agency-wide viewers (projects.view.all) may access any project in their tenant. Project-scoped
        // users (e.g. client viewers) may only access projects they are an active member of — checked
        // before the project context is set so the membership lookup itself spans projects.
        if (! $user->hasPermission('projects.view.all')) {
            $isMember = ProjectMembership::query()
                ->where('project_id', $project->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();
            abort_unless($isMember, 403, 'You do not have access to this project.');
        }

        $this->context->setProjectId((string) $project->id);

        return $next($request);
    }
}
