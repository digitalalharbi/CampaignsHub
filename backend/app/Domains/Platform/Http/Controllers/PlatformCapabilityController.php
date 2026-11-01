<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Audit\AuditLogger;
use App\Domains\Legal\Models\PlatformSetting;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SUPERADMIN-FEATURES-001 — which capabilities exist for whom, decided from the console.
 *
 * Two levels the console did not have, both read by the SAME resolver the `module:` middleware enforces (`Capabilities::resolve`),
 * so a switch here is refused on the server, not merely hidden:
 * - platform: off for every workspace (joins the environment's `capabilities.disabled`);
 * - tenant: the workspace-level switch a tenant's own settings write — on, off, or back to inheriting its plan.
 * Plans' own features stay on the plans page; entitlement by grant stays on the grants panel. Every change is audited with a reason.
 */
final class PlatformCapabilityController extends Controller
{
    public function __construct(
        private readonly Capabilities $capabilities,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenants,
    ) {}

    /** GET /api/v1/admin/capabilities */
    public function index(): JsonResponse
    {
        $console = (array) (PlatformSetting::current()->disabled_capabilities ?? []);
        $env = array_map('strval', (array) config('capabilities.disabled', []));

        return ApiResponse::success(['capabilities' => array_map(fn (string $module) => [
            'module' => $module,
            'activation' => (string) (Capabilities::catalogue()[$module]['activation'] ?? 'default_on'),
            'platform_disabled' => in_array($module, $console, true) || in_array($module, $env, true),
            // An environment kill switch cannot be undone from here; it is shown so the console does not pretend otherwise.
            'disabled_by_environment' => in_array($module, $env, true),
        ], array_keys(Capabilities::catalogue()))], 'Capabilities.');
    }

    /** PUT /api/v1/admin/capabilities/{module} — on or off for every workspace. */
    public function setPlatform(Request $request, string $module): JsonResponse
    {
        abort_unless(Capabilities::exists($module), 404);
        $data = $request->validate(['disabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);

        $settings = PlatformSetting::current();
        $before = (array) ($settings->disabled_capabilities ?? []);
        $after = $data['disabled']
            ? array_values(array_unique([...$before, $module]))
            : array_values(array_diff($before, [$module]));
        $settings->forceFill(['disabled_capabilities' => $after])->save();

        $this->tenants->enterPlatformScope();
        Tenant::query()->pluck('id')->each(fn ($id) => $this->capabilities->bump((string) $id));

        $this->audit->log(
            action: 'platform.capability.'.($data['disabled'] ? 'disabled' : 'enabled'),
            entityType: 'capability',
            entityId: $module,
            before: ['disabled' => in_array($module, $before, true)],
            after: ['disabled' => $data['disabled']],
            reason: $data['reason'],
            userId: $request->user()?->id,
        );

        return $this->index();
    }

    /** GET /api/v1/admin/tenants/{tenant}/capabilities — every module's state for this workspace, and its own switch. */
    public function tenant(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);

        return ApiResponse::success(['capabilities' => $this->describe($model)], 'Workspace capabilities.');
    }

    /** PUT /api/v1/admin/tenants/{tenant}/capabilities/{module} — `enabled` true / false, or null to inherit again. */
    public function setTenant(Request $request, string $tenant, string $module): JsonResponse
    {
        abort_unless(Capabilities::exists($module), 404);
        $data = $request->validate(['enabled' => ['present', 'nullable', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']]);

        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);
        $key = ['tenant_id' => $model->getKey(), 'scope_type' => CapabilitySwitch::TENANT, 'scope_key' => (string) $model->getKey(), 'module' => $module];
        $before = CapabilitySwitch::withoutGlobalScopes()->where($key)->value('enabled');

        if ($data['enabled'] === null) {
            CapabilitySwitch::withoutGlobalScopes()->where($key)->delete();
        } else {
            CapabilitySwitch::withoutGlobalScopes()->updateOrCreate($key, ['scope_id' => null, 'enabled' => $data['enabled'], 'changed_by' => $request->user()?->getKey()]);
        }
        $this->capabilities->bump((string) $model->getKey());

        $this->audit->log(
            action: 'platform.tenant.capability_set',
            entityType: 'capability',
            entityId: $module,
            before: ['enabled' => $before],
            after: ['enabled' => $data['enabled']],
            reason: $data['reason'],
            userId: $request->user()?->id,
            tenantId: (string) $model->getKey(),
        );

        return ApiResponse::success(['capabilities' => $this->describe($model)], 'Saved.');
    }

    /** @return list<array<string, mixed>> */
    private function describe(Tenant $tenant): array
    {
        $switches = CapabilitySwitch::withoutGlobalScopes()
            ->where(['tenant_id' => $tenant->getKey(), 'scope_type' => CapabilitySwitch::TENANT, 'scope_key' => (string) $tenant->getKey()])
            ->pluck('enabled', 'module');

        return array_values(array_map(fn ($state) => [
            ...$state->toArray(),
            'tenant_switch' => $switches->has($state->module) ? (bool) $switches[$state->module] : null,
        ], $this->capabilities->resolve($tenant)));
    }
}
