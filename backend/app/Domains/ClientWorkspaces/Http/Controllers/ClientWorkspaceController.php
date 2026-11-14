<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\WorkspaceMode;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Resources\ClientWorkspaceResource;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Rules\UserInCurrentTenant;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            // CLIENT-UNIT-001 — «عميل جديد» is ONE form: the client, its internal project and its portal.
            'default_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'account_manager_id' => ['sometimes', 'nullable', 'integer', new UserInCurrentTenant],
            'create_project' => ['sometimes', 'boolean'],
        ]);

        $withProject = (bool) ($validated['create_project'] ?? false);

        /*
         * The project is a second thing the plan counts, so its cap is checked HERE, before anything is written: the
         * `clients` middleware only knows about clients, and a client created without the project it was promised —
         * or a project past the cap — is the half-made state this form exists to prevent.
         */
        if ($withProject) {
            abort_unless($request->user()->hasPermission('projects.create'), 403);
            $tenant = Tenant::query()->findOrFail(app(TenantContext::class)->tenantId());
            if (! app(SubscriptionService::class)->withinLimit($tenant, 'projects')) {
                return app(EnsureWithinPlanLimit::class)->refusal($tenant, 'projects');
            }
        }

        [$workspace, $project] = DB::transaction(function () use ($validated, $withProject): array {
            $workspace = ClientWorkspace::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'mode' => $validated['mode'],
                'branding' => $validated['branding'] ?? null,
                'limits' => $validated['limits'] ?? null,
                'default_currency' => isset($validated['default_currency']) ? strtoupper($validated['default_currency']) : null,
            ]);

            // The internal project carries the client's name and the manager; it is hidden in the interface until a
            // second project is added (see `meta.internal`). Ids, routes and tenant isolation are unchanged.
            $project = $withProject ? Project::create([
                'client_workspace_id' => (string) $workspace->id,
                'name' => $validated['name'],
                'account_manager_id' => $validated['account_manager_id'] ?? null,
                'status' => 'active',
                'setup_completion' => 0,
                'meta' => ['internal' => true],
            ]) : null;

            return [$workspace, $project];
        });

        $audit->log(action: 'client_workspace.created', entityType: ClientWorkspace::class, entityId: (string) $workspace->id, after: ['name' => $workspace->name, 'mode' => $workspace->mode, 'internal_project_id' => $project?->id]);

        return ApiResponse::success(
            array_merge((new ClientWorkspaceResource($workspace))->resolve($request), [
                'project_id' => $project?->id,
                'portal_path' => '/portal/'.$workspace->slug,
            ]),
            'Client workspace created.',
            status: 201,
        );
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
        $this->assertReach($request, (string) $clientWorkspace->id);

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
        $this->assertReach($request, (string) $clientWorkspace->id);
        $clientWorkspace->delete(); // soft delete
        $audit->log(action: 'client_workspace.archived', entityType: ClientWorkspace::class, entityId: (string) $clientWorkspace->id);

        return ApiResponse::success(null, 'Client workspace archived.');
    }

    public function restore(Request $request, string $clientWorkspace, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('clients.delete'), 403);
        $ws = ClientWorkspace::withTrashed()->findOrFail($clientWorkspace);
        $this->assertReach($request, (string) $ws->id);
        $ws->restore();
        $audit->log(action: 'client_workspace.restored', entityType: ClientWorkspace::class, entityId: (string) $ws->id);

        return ApiResponse::success(new ClientWorkspaceResource($ws->loadCount('projects')), 'Client workspace restored.');
    }

    /** A write needs the same reach as the read: an id outside the ceiling is not confirmed to exist (404). */
    private function assertReach(Request $request, string $clientId): void
    {
        abort_unless($this->scope->canReach($request->user(), $clientId), 404);
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
