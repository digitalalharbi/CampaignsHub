<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\WorkspaceMode;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Resources\ClientWorkspaceResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ClientWorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('workspaces.view'), 403);

        return ApiResponse::success(
            ClientWorkspaceResource::collection(ClientWorkspace::withCount('projects')->latest()->get()),
            'Client workspaces retrieved.',
        );
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('workspaces.create'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'mode' => ['required', Rule::in(WorkspaceMode::values())],
            'branding' => ['nullable', 'array'],
            'limits' => ['nullable', 'array'],
        ]);

        $workspace = ClientWorkspace::create([
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($validated['name']),
            'mode' => $validated['mode'],
            'branding' => $validated['branding'] ?? null,
            'limits' => $validated['limits'] ?? null,
        ]);

        $audit->log(action: 'client_workspace.created', entityType: ClientWorkspace::class, entityId: (string) $workspace->id, after: ['name' => $workspace->name, 'mode' => $workspace->mode]);

        return ApiResponse::success(new ClientWorkspaceResource($workspace), 'Client workspace created.', status: 201);
    }

    public function show(Request $request, ClientWorkspace $clientWorkspace): JsonResponse
    {
        abort_unless($request->user()->hasPermission('workspaces.view'), 403);

        return ApiResponse::success(
            new ClientWorkspaceResource($clientWorkspace->loadCount('projects')),
            'Client workspace retrieved.',
        );
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 1;
        while (ClientWorkspace::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
