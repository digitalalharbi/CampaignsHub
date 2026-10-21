<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Http\Controllers;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CAP-REGISTRY-001 — which modules this workspace has, and where each is switched on or off.
 *
 * Reading is open to any member (a screen has to know what to show). Changing a switch needs
 * `settings.manage`, is bound to THIS tenant's clients and projects, and is audited with its before and
 * after. A switch ON is refused for a module the workspace is not entitled to: switching is never a way
 * to acquire a module.
 */
final class CapabilityController extends Controller
{
    public function __construct(private readonly Capabilities $capabilities, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        [$clientId, $projectId] = $this->scopeFromQuery($request);
        $states = $this->capabilities->resolve($tenant, $clientId, $projectId);
        $locale = $request->user()?->locale === 'en' ? 'en' : 'ar';

        return ApiResponse::success(array_values(array_map(
            static fn ($state): array => $state->toArray() + [
                'label' => Capabilities::catalogue()[$state->module]['label'][$locale] ?? $state->module,
            ],
            $states,
        )));
    }

    public function update(Request $request, string $module): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        abort_unless(Capabilities::exists($module), 404);

        $data = $request->validate([
            'scope' => ['required', Rule::in([CapabilitySwitch::TENANT, CapabilitySwitch::CLIENT, CapabilitySwitch::PROJECT])],
            'scope_id' => ['nullable', 'uuid'],
            'enabled' => ['required', 'boolean'],
        ]);

        $tenant = $this->tenant();
        [$scopeId, $scopeKey] = $this->scopeIds($tenant, $data['scope'], $data['scope_id'] ?? null);

        if ($data['enabled'] === true) {
            $state = $this->capabilities->resolve($tenant)[$module];
            if (! $state->entitled) {
                return ApiResponse::error(
                    'This workspace does not include this module.',
                    ['module' => [$state->reason ?? 'not_entitled']],
                    status: 422,
                );
            }
        }

        $existing = CapabilitySwitch::withoutGlobalScopes()->where([
            'tenant_id' => $tenant->getKey(), 'scope_type' => $data['scope'], 'scope_key' => $scopeKey, 'module' => $module,
        ])->first();
        $before = $existing?->enabled;

        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->getKey(), 'scope_type' => $data['scope'], 'scope_key' => $scopeKey, 'module' => $module],
            ['scope_id' => $scopeId, 'enabled' => $data['enabled'], 'changed_by' => $request->user()->getKey()],
        );

        $this->audit->log(
            'capability.changed', 'capability', $module,
            ['enabled' => $before], ['enabled' => $data['enabled'], 'scope' => $data['scope'], 'scope_id' => $scopeId],
        );

        return $this->index($request);
    }

    /** Remove a switch, so the level inherits again. */
    public function destroy(Request $request, string $module): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        abort_unless(Capabilities::exists($module), 404);

        $data = $request->validate([
            'scope' => ['required', Rule::in([CapabilitySwitch::TENANT, CapabilitySwitch::CLIENT, CapabilitySwitch::PROJECT])],
            'scope_id' => ['nullable', 'uuid'],
        ]);

        $tenant = $this->tenant();
        [$scopeId, $scopeKey] = $this->scopeIds($tenant, $data['scope'], $data['scope_id'] ?? null);

        $existing = CapabilitySwitch::withoutGlobalScopes()->where([
            'tenant_id' => $tenant->getKey(), 'scope_type' => $data['scope'], 'scope_key' => $scopeKey, 'module' => $module,
        ])->first();

        if ($existing !== null) {
            $existing->delete();
            $this->audit->log('capability.cleared', 'capability', $module, ['enabled' => $existing->enabled], ['scope' => $data['scope'], 'scope_id' => $scopeId]);
        }

        return $this->index($request);
    }

    /** @return array{0: ?string, 1: ?string} client id and project id, each proven to belong to this tenant */
    private function scopeFromQuery(Request $request): array
    {
        $clientId = $request->query('client_id');
        $projectId = $request->query('project_id');
        $tenantId = (string) $this->tenant()->getKey();

        $client = null;
        if (is_string($clientId) && $clientId !== '') {
            abort_unless(ClientWorkspace::query()->where('tenant_id', $tenantId)->whereKey($clientId)->exists(), 404);
            $client = $clientId;
        }

        $project = null;
        if (is_string($projectId) && $projectId !== '') {
            $row = Project::query()->where('tenant_id', $tenantId)->whereKey($projectId)->first();
            abort_if($row === null, 404);
            $project = $projectId;
            // A project's client is part of its own answer — a project cannot be asked about under another client.
            $client ??= (string) $row->client_workspace_id;
        }

        return [$client, $project];
    }

    /** @return array{0: ?string, 1: string} the stored scope id (null for the tenant) and the uniqueness key */
    private function scopeIds(Tenant $tenant, string $scope, ?string $scopeId): array
    {
        if ($scope === CapabilitySwitch::TENANT) {
            return [null, (string) $tenant->getKey()];
        }

        abort_if($scopeId === null, 422, 'scope_id is required.');

        $exists = $scope === CapabilitySwitch::CLIENT
            ? ClientWorkspace::query()->where('tenant_id', $tenant->getKey())->whereKey($scopeId)->exists()
            : Project::query()->where('tenant_id', $tenant->getKey())->whereKey($scopeId)->exists();
        abort_unless($exists, 404);

        return [$scopeId, $scopeId];
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return Tenant::query()->findOrFail($id);
    }
}
