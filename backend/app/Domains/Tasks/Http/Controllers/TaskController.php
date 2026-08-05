<?php

declare(strict_types=1);

namespace App\Domains\Tasks\Http\Controllers;

use App\Domains\Tasks\Models\Task;
use App\Domains\Tasks\Resources\TaskResource;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class TaskController extends Controller
{
    private const STATUSES = ['backlog', 'todo', 'in_progress', 'waiting_client', 'blocked', 'review', 'completed', 'cancelled'];

    public function __construct(private readonly ClientScopeResolver $scopes) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.view'), 403);

        /*
         * The agency ceiling, which this list did not have (AGENCY-PERMS).
         *
         * `manager@demo-agency.local` is confined to ONE client, and the demo agency runs five —
         * yet this endpoint returned all 2105 tasks in the tenant, because tenant isolation was the
         * only filter and a client scope is a second, narrower one. Found by signing in as the
         * scoped fixture and counting, not by a test: every existing test asserted the tenant
         * boundary, which was never the boundary that leaked.
         */
        $query = $this->scopes->constrainAllowingOwn(
            Task::query()->latest(),
            $request->user(),
            ['created_by', 'assignee_id'],
        );
        foreach (['status', 'project_id', 'client_workspace_id'] as $filter) {
            if ($value = $request->string($filter)->toString()) {
                $query->where($filter, $value);
            }
        }
        if ($request->boolean('mine')) {
            $query->where('assignee_id', $request->user()->id);
        }

        return ApiResponse::success(TaskResource::collection($query->get()), 'Tasks retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.create'), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'project_id' => ['nullable', 'uuid', Rule::exists('projects', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'client_workspace_id' => ['nullable', 'uuid', Rule::exists('client_workspaces', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'checklist' => ['nullable', 'array'],
        ]);

        $task = Task::create(array_merge($validated, ['created_by' => Auth::id()]));

        return ApiResponse::success(new TaskResource($task), 'Task created.', status: 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission('tasks.update'), 403);

        // The same ceiling the list obeys — otherwise a task hidden from the roster could still be
        // rewritten by guessing its id, which is the whole point of enforcing scope in the backend.
        abort_unless($this->scopes->canReachRow(
            $request->user(),
            $task->client_workspace_id === null ? null : (string) $task->client_workspace_id,
            (int) $task->created_by === (int) $request->user()->id || (int) $task->assignee_id === (int) $request->user()->id,
        ), 403);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
            'checklist' => ['nullable', 'array'],
        ]);

        $task->update($validated);

        return ApiResponse::success(new TaskResource($task), 'Task updated.');
    }
}
