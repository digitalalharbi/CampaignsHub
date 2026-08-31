<?php

declare(strict_types=1);

namespace App\Domains\Projects\Middleware;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Domains\Projects\Context\ProjectContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEAM-PROJECT-RBAC-001 — the refusal, on the route, where it cannot be walked around.
 *
 * ## Hiding a menu item is not security
 *
 * A navigation entry that is not drawn is a navigation entry. The URL is still there, the API is
 * still there, and both are one `curl` away from anybody who has ever seen the page. So the check
 * lives on the route: `->middleware('project.can:leads.pii.view')`, evaluated server-side, on every
 * request, whether it came from our own screen or from something else entirely.
 *
 * ## 403, not 404, and not an empty 200
 *
 * The three ways to refuse are not equivalent. An empty 200 is the worst — the caller believes there
 * are no leads, which is a false statement about the client's business rather than a refusal. A 404
 * hides existence, which is right for another tenant's data (and is what `ResolveProject` already
 * does) and wrong here, where the reader is a colleague who should be told to ask for access.
 *
 * Runs after `ResolveProject`, which has already established that the project exists inside this
 * tenant and that the reader may reach it at all. This answers the narrower question of what they
 * may do once inside.
 */
final class RequireProjectCapability
{
    public function __construct(
        private readonly ProjectAbilities $abilities,
        private readonly ProjectContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();
        $projectId = $this->context->projectId() ?? (string) $request->route('project');

        /*
         * No user, no project, or no grant — all the same answer. Distinguishing them in the
         * response would tell an unauthenticated caller which projects exist.
         */
        abort_unless(
            $user !== null && $projectId !== '' && $this->abilities->allows($user, $projectId, $capability),
            403,
            'You do not have this permission on this project.',
        );

        return $next($request);
    }
}
