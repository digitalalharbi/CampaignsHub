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

    /** The statuses that mean «still somebody's problem» — the same list the page has always used. */
    private const OPEN_STATUSES = ['backlog', 'todo', 'in_progress', 'waiting_client', 'blocked', 'review'];

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
        foreach (['status', 'priority', 'project_id', 'client_workspace_id'] as $filter) {
            if ($value = $request->string($filter)->toString()) {
                $query->where($filter, $value);
            }
        }
        if ($request->boolean('mine')) {
            $query->where('assignee_id', $request->user()->id);
        }

        /*
         * `priority` and `q` moved here from the browser, and they had to.
         *
         * The page filtered the whole table in memory, which worked only because the whole table was
         * sent. Once the response is a PAGE, a filter the server does not know about searches one
         * page and silently misses every match behind it — a search box that answers «no results»
         * about a task the workspace is holding.
         */
        if ($needle = trim($request->string('q')->toString())) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $needle).'%';
            $query->where(function ($q) use ($like): void {
                $q->where('title', 'ilike', $like)->orWhere('description', 'ilike', $like);
            });
        }

        /*
         * TASKS-LEDGER-001 — the page is bounded, and the counts describe the LEDGER.
         *
         * This ended in `$query->get()` with no limit: the comment above cites 2,105 tasks in one
         * tenant, and every visit fetched and rendered all of them. The page then filtered that array
         * in the browser and computed «open», «overdue» and «done» from it.
         *
         * Both halves have to move together. Paginating alone would leave those three counts
         * describing whichever page arrived — the exact defect the alerts queue was fixed for, «the
         * queue counts what exists, not what fitted», reintroduced on the next screen along. So the
         * counts are taken over the whole filtered scope, in one grouped query, and travel in `meta`
         * beside the page.
         *
         * `clone` before counting: `paginate()` mutates the builder it runs on.
         */
        $counting = clone $query;

        $page = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));

        $byStatus = (clone $counting)->reorder()
            ->getQuery()
            ->select('status')
            ->selectRaw('count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $open = 0;
        $done = 0;
        foreach ($byStatus as $status => $count) {
            if (in_array($status, self::OPEN_STATUSES, true)) {
                $open += (int) $count;
            } elseif ($status === 'completed') {
                $done += (int) $count;
            }
        }

        /* Overdue is the model's own rule: a due date in the past that nobody has finished. */
        $overdue = (clone $counting)->reorder()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->count();

        return ApiResponse::success(
            TaskResource::collection($page->items()),
            'Tasks retrieved.',
            meta: [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'counts' => ['open' => $open, 'done' => $done, 'overdue' => $overdue],
            ],
        );
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
