<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Access\ProjectAbilities;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * TEAM-PROJECT-RBAC-001 — what THIS person may do in THIS project, said once.
 *
 * ## What this is for, and what it is emphatically not
 *
 * It is not authorisation. Every route under `projects/{project}` states its own capability and the
 * middleware refuses without it; that is the enforcement and it does not consult this endpoint.
 * Hiding a menu item is not security, and an endpoint that listed capabilities would not become
 * security by being read by a menu.
 *
 * What it is for is the other half: not offering a door that answers 403. A media buyer on a client's
 * project sees «Team & permissions» in the rail today, clicks it, and is refused — which reads as a
 * broken product rather than as a boundary, and teaches them to distrust the rail. The navigation can
 * only stop offering it if it knows, and today it knows nothing: the agency rail is a static list.
 *
 * ## Why the whole set rather than a question at a time
 *
 * A rail asks about a dozen capabilities to draw itself. Asking one at a time is a dozen round trips
 * for one render, and a per-question endpoint invites callers to ask about capabilities they are
 * about to act on — which is the pattern that ends with the CLIENT deciding. One list, read once per
 * project, used only to decide what to draw.
 */
final class ProjectCapabilityController extends Controller
{
    public function __construct(private readonly ProjectAbilities $abilities) {}

    public function index(Request $request, string $project): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        /*
         * The project comes from the route, and the `project` middleware has already established that
         * this person may reach it at all. Resolving from the route rather than from the context means
         * the answer is about the project in the URL — a caller reading the rail for one project while
         * another is in context would otherwise be told about the wrong one.
         */
        return ApiResponse::success(
            ['capabilities' => $this->abilities->for($user, $project)],
            'What this person may do in this project.',
        );
    }
}
