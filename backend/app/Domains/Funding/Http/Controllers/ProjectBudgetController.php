<?php

declare(strict_types=1);

namespace App\Domains\Funding\Http\Controllers;

use App\Domains\Funding\Services\FundingReach;
use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Funding\Support\FundingDisclaimer;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ONE-BUDGET-001 — GET projects/{project}/approved-budget: the project's approved budget, for the project-scoped
 * surfaces (the campaigns page). The same reading as /agency/budgets, over the budget's own period; it takes no date
 * range. Guarded by `project.can:budget.view` on the route; a membership that withholds money reads no figure.
 */
final class ProjectBudgetController extends Controller
{
    public function __invoke(Request $request, string $project, ProjectBudgetReader $budgets, FundingReach $reach): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $reading = $reach->moneyWithheld($user, $project) ? ProjectBudgetReader::none() + ['withheld' => true] : $budgets->forProject($project);

        return ApiResponse::success($reading, 'Approved budget.', ['disclaimer' => FundingDisclaimer::both()]);
    }
}
