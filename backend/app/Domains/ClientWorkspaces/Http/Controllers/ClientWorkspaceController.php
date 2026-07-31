<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\WorkspaceMode;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Resources\ClientWorkspaceResource;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The agency's client workspaces.
 *
 * Tenant-scoped by the model's global scope, and CLIENT-scoped here by the membership's ceiling
 * (REG-001). It was only the former: an account manager confined to three clients got the whole
 * agency's roster from this endpoint, and could open any one of them by id — the narrowing existed
 * on the portfolio surface next door and had never been applied to this one.
 */
final class ClientWorkspaceController extends Controller
{
    public function __construct(private readonly ClientScopeResolver $scope) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('workspaces.view'), 403);

        $query = $this->scope->constrain(
            ClientWorkspace::withCount('projects')->latest(),
            $request->user(),
            'id', // the ceiling names client ids, and here the client IS the row
        );

        return ApiResponse::success(
            ClientWorkspaceResource::collection($query->get()),
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
        // 404 rather than 403: an id outside the ceiling must not be confirmed to exist.
        abort_unless($this->scope->canReach($request->user(), (string) $clientWorkspace->id), 404);

        return ApiResponse::success(
            new ClientWorkspaceResource($clientWorkspace->loadCount('projects')),
            'Client workspace retrieved.',
        );
    }

    public function update(Request $request, ClientWorkspace $clientWorkspace, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('clients.update'), 403);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'mode' => ['sometimes', 'required', Rule::in(WorkspaceMode::values())],
            'branding' => ['nullable', 'array'],
            'limits' => ['nullable', 'array'],
        ]);
        $before = $clientWorkspace->only(['name', 'mode', 'branding']);
        $clientWorkspace->fill($validated)->save();

        $audit->log(action: 'client_workspace.updated', entityType: ClientWorkspace::class, entityId: (string) $clientWorkspace->id, before: $before, after: $clientWorkspace->only(['name', 'mode', 'branding']));

        return ApiResponse::success(new ClientWorkspaceResource($clientWorkspace->loadCount('projects')), 'Client workspace updated.');
    }

    public function archive(Request $request, ClientWorkspace $clientWorkspace, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('clients.delete'), 403);
        $clientWorkspace->delete(); // soft delete
        $audit->log(action: 'client_workspace.archived', entityType: ClientWorkspace::class, entityId: (string) $clientWorkspace->id);

        return ApiResponse::success(null, 'Client workspace archived.');
    }

    public function restore(Request $request, string $clientWorkspace, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('clients.delete'), 403);
        $ws = ClientWorkspace::withTrashed()->findOrFail($clientWorkspace);
        $ws->restore();
        $audit->log(action: 'client_workspace.restored', entityType: ClientWorkspace::class, entityId: (string) $ws->id);

        return ApiResponse::success(new ClientWorkspaceResource($ws->loadCount('projects')), 'Client workspace restored.');
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
