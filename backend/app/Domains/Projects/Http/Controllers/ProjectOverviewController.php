<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tasks\Models\Task;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Executive summary for a project. Returns REAL counts for built domains and an explicit
 * "not_available_yet" marker for domains not yet built — never a misleading zero.
 */
final class ProjectOverviewController extends Controller
{
    public function show(Request $request, ProjectContext $context): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.view'), 403);

        // ProjectContext is set by ResolveProject; models auto-scope to it.
        $project = Project::findOrFail($context->projectId());

        $openTasks = Task::whereNotIn('status', ['completed', 'cancelled'])->count();
        $overdueTasks = Task::whereNotIn('status', ['completed', 'cancelled'])
            ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count();

        return ApiResponse::success([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'setup_completion' => $project->setup_completion,
                'client_workspace_id' => $project->client_workspace_id,
                'account_manager_id' => $project->account_manager_id,
            ],
            'built' => [
                'team_members' => ProjectMembership::count(),
                'bound_accounts' => ProjectIntegrationBinding::count(),
                'open_tasks' => $openTasks,
                'overdue_tasks' => $overdueTasks,
                'unread_notifications' => AppNotification::where('user_id', $request->user()->id)
                    ->where('status', 'unread')->count(),
            ],
            // Domains not yet implemented — surfaced honestly, not as zeros.
            'not_available_yet' => [
                'campaigns', 'spend', 'revenue', 'roas', 'tracking_health', 'onboarding_progress',
                'active_campaigns', 'critical_alerts',
            ],
        ], 'Project overview.');
    }
}
