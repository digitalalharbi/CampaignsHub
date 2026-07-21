<?php

declare(strict_types=1);

namespace App\Domains\Projects\Http\Controllers;

use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Resources\ProjectResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.view'), 403);

        $query = Project::query()->latest();
        if ($workspace = $request->string('client_workspace_id')->toString()) {
            $query->where('client_workspace_id', $workspace);
        }

        return ApiResponse::success(ProjectResource::collection($query->get()), 'Projects retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('projects.create'), 403);

        $validated = $request->validate([
            'client_workspace_id' => ['required', 'uuid', Rule::exists('client_workspaces', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'name' => ['required', 'string', 'max:160'],
            'account_manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $project = Project::create([
            'client_workspace_id' => $validated['client_workspace_id'],
            'name' => $validated['name'],
            'account_manager_id' => $validated['account_manager_id'] ?? null,
            'status' => 'setup',
            'setup_completion' => 0,
        ]);

        return ApiResponse::success(new ProjectResource($project), 'Project created.', status: 201);
    }
}
