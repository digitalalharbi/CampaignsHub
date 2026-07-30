<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The permission catalogue and the platform's integration picture (ADMIN-003).
 *
 * Both are READ surfaces, and deliberately so.
 *
 * The permission catalogue is defined in `PermissionSeeder` — code, reviewed, and applied by
 * migration. Making it editable from a console would mean a key could be invented at runtime that no
 * `hasPermission()` call anywhere in the product checks for: a permission that grants nothing, shown
 * in every role editor as though it did. Roles are where tenants combine these, and roles already
 * have their own tenant-scoped surface.
 *
 * The integrations view answers a question no tenant's own page can: how many workspaces have
 * actually connected each provider. Provider connection states stay exactly as the tenant-facing
 * surfaces report them — this counts them, it does not reinterpret them, and it never invents a
 * "connected" that no provider confirmed.
 */
final class PlatformAccessController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** GET /api/v1/admin/permissions */
    public function permissions(): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $permissions = Permission::query()->orderBy('group')->orderBy('key')->get();

        // How many roles across the platform actually grant each one. A permission granted by nothing
        // is either newly added or dead, and the owner cannot tell which from a list of keys alone.
        $usage = DB::table('permission_role')
            ->selectRaw('permission_id, count(distinct role_id) as c')
            ->groupBy('permission_id')->pluck('c', 'permission_id');

        return ApiResponse::success([
            'groups' => $permissions->groupBy('group')->map(fn ($rows, $group) => [
                'group' => (string) $group,
                'permissions' => $rows->map(fn (Permission $p) => [
                    'id' => (string) $p->getKey(),
                    'key' => $p->key,
                    'description' => $p->description,
                    'granted_by_roles' => (int) ($usage[$p->getKey()] ?? 0),
                ])->values()->all(),
            ])->values()->all(),
            'total' => $permissions->count(),
            'roles' => Role::query()->count(),
            // Said plainly so nobody looks for an edit button that should not exist.
            'editable' => false,
            'note' => 'The catalogue is defined in code (PermissionSeeder). A key invented at runtime '
                .'would grant nothing, because no hasPermission() call checks for it.',
        ], 'Permission catalogue.');
    }

    /**
     * GET /api/v1/admin/integrations — how many workspaces have connected each provider.
     *
     * Counts only. The honest-state vocabulary the tenant surfaces use is preserved verbatim rather
     * than collapsed into "connected / not connected", because the difference between "awaiting
     * credentials" and "failed" is the whole content of the answer.
     */
    public function integrations(): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        $rows = ProviderConnection::query()
            ->selectRaw('provider, status, count(*) as c, count(distinct tenant_id) as t')
            ->groupBy('provider', 'status')->get();

        return ApiResponse::success([
            'providers' => $rows->groupBy('provider')->map(fn ($statuses, $provider) => [
                'provider' => (string) $provider,
                'tenants' => (int) $statuses->max('t'),
                'by_status' => $statuses->mapWithKeys(fn ($r) => [(string) $r->status => (int) $r->c])->all(),
            ])->values()->all(),
            'note' => 'Counts only. Provider states are reported exactly as the tenant surfaces report '
                .'them — no state is reinterpreted here.',
        ], 'Platform integrations.');
    }
}
