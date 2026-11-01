<?php

declare(strict_types=1);

namespace App\Domains\Projects\Middleware;

use App\Domains\Projects\Access\ProjectReach;
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
    public function __construct(private readonly ProjectContext $context, private readonly ProjectReach $reach) {}

    public function handle(Request $request, Closure $next): Response
    {
        $projectId = (string) $request->route('project');

        // Tenant global scope is already active (ResolveMembership runs first), so a cross-tenant id
        // simply returns null here — fail-closed.
        $project = Project::find($projectId);
        abort_if($project === null, 404, 'Project not found.');

        $user = $request->user();
        abort_unless($user?->hasPermission('projects.view'), 403);

        // SEC-1 — one answer, shared with the listing and the portfolio: the membership's named client /
        // project ceiling first (it outranks `projects.view.all`), then `projects.view.all` or a CURRENT
        // project membership (active, `expires_at` not past). Checked before the project context is set so
        // the membership lookup itself spans projects.
        abort_unless($this->reach->canReach($user, (string) $project->id), 403, 'You do not have access to this project.');

        $this->context->setProjectId((string) $project->id);

        return $next($request);
    }
}
