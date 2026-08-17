<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Services\CanonicalWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Projects\Resources\ProjectResource;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectController extends Controller
{
    private const STATUSES = ['draft', 'onboarding', 'active', 'paused', 'completed', 'archived'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission('projects.view'), 403);

        $query = Project::query()->latest();
        // Agency-wide viewers (projects.view.all) see every project in the tenant; project-scoped
        // users (e.g. client viewers) see only the projects they are an active member of.
        if (! $user->hasPermission('projects.view.all')) {
            $memberProjectIds = ProjectMembership::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('project_id');
            $query->whereIn('id', $memberProjectIds);
        }
        if ($workspace = $request->string('client_workspace_id')->toString()) {
            $query->where('client_workspace_id', $workspace);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        } elseif (! $request->boolean('include_archived')) {
            $query->where('status', '!=', 'archived');
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        return ApiResponse::success(ProjectResource::collection($query->get()), 'Projects retrieved.');
    }

    public function show(Request $request, string $project): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.view'), 403);
        $model = $this->find($project);

        return ApiResponse::success(new ProjectResource($model), 'Project retrieved.');
    }

    /**
     * PROJECT-CREATE-WORKSPACE-001 — `client_workspace_id` is required of an AGENCY, not of everyone.
     *
     * It used to be required of everyone, which made project creation impossible for an advertiser:
     * a `client_workspaces` row is an agency's client, and somebody running their own campaigns has
     * none. That is what the connection wizard was working around with `workspaces.data?.[0]?.id` —
     * an expression that returns nothing for an advertiser and the WRONG client for an agency, and
     * whose failure reached production as «حدث خطأ غير متوقع.»
     *
     * So the field is optional in the payload and resolved in the domain: an advertiser's canonical
     * container, or — when the answer is genuinely the customer's to give — a validation error
     * naming the field, which the interface can turn into a question instead of a dead end.
     */
    public function store(Request $request, AuditLogger $audit, CanonicalWorkspace $containers): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.create'), 403);

        $validated = $request->validate([
            'client_workspace_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('client_workspaces', 'id')->where('tenant_id', app(TenantContext::class)->tenantId())],
            'name' => ['required', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'account_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $workspaceId = $validated['client_workspace_id'] ?? null;

        if ($workspaceId === null) {
            $tenant = Tenant::withoutGlobalScopes()->findOrFail(app(TenantContext::class)->tenantId());
            $canonical = $containers->ensureFor($tenant);

            // An agency is asked, and told what to answer. Never guessed at — filing a project under
            // whichever client sorted first is how one client's work reaches another client's portal.
            if ($canonical === null) {
                throw ValidationException::withMessages([
                    'client_workspace_id' => [__('validation.client_workspace_required')],
                ]);
            }

            $workspaceId = (string) $canonical->getKey();
        }

        $project = Project::create([
            'client_workspace_id' => $workspaceId,
            'name' => $validated['name'],
            'account_manager_id' => $validated['account_manager_id'] ?? null,
            'status' => $validated['status'] ?? 'draft',
            'setup_completion' => 0,
        ]);

        $audit->log(action: 'project.created', entityType: Project::class, entityId: (string) $project->id, after: ['name' => $project->name]);

        return ApiResponse::success(new ProjectResource($project), 'Project created.', status: 201);
    }

    public function update(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.update'), 403);
        $model = $this->find($project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'setup_completion' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'account_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $before = $model->only(['name', 'status', 'setup_completion']);
        $model->update($validated);
        $audit->log(action: 'project.updated', entityType: Project::class, entityId: (string) $model->id, before: $before, after: $model->only(['name', 'status', 'setup_completion']));

        return ApiResponse::success(new ProjectResource($model), 'Project updated.');
    }

    /** Duplicate a project's scaffold (name + workspace + manager); bindings are NOT copied. */
    public function clone(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.create'), 403);
        $source = $this->find($project);

        $copy = Project::create([
            'client_workspace_id' => $source->client_workspace_id,
            'name' => $source->name.' (Copy)',
            'account_manager_id' => $source->account_manager_id,
            'status' => 'draft',
            'setup_completion' => 0,
            'meta' => $source->meta,
        ]);

        $audit->log(action: 'project.cloned', entityType: Project::class, entityId: (string) $copy->id, after: ['source' => $source->id]);

        return ApiResponse::success(new ProjectResource($copy), 'Project cloned.', status: 201);
    }

    public function archive(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $project, 'archived', 'projects.update', 'project.archived', $audit);
    }

    public function restore(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $project, 'active', 'projects.update', 'project.restored', $audit);
    }

    public function pause(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $project, 'paused', 'projects.update', 'project.paused', $audit);
    }

    public function resume(Request $request, string $project, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $project, 'active', 'projects.update', 'project.resumed', $audit);
    }

    private function transition(Request $request, string $project, string $status, string $permission, string $action, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission($permission), 403);
        $model = $this->find($project);
        $model->update(['status' => $status]);
        $audit->log(action: $action, entityType: Project::class, entityId: (string) $model->id, after: ['status' => $status]);

        return ApiResponse::success(new ProjectResource($model), 'Project status updated.');
    }

    /** Tenant-scoped lookup (global TenantScope makes cross-tenant fail-closed → 404). */
    private function find(string $id): Project
    {
        $model = Project::find($id);
        abort_if($model === null, 404, 'Project not found.');

        return $model;
    }
}
