<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\AccessSections;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ACCESS-SECTIONS-001 — GET /api/v1/me/access: what the signed-in member may open in the bound workspace, answered by
 * the one resolver (`HasRoles::permissionKeys()` + the member's override rows + the client ceiling). The navigation
 * hides every section not listed; the server refuses them regardless (`EnforceSectionAccess` and each route's own
 * check). Read fresh on every request, so a change made in the access center shows on the next fetch, no re-login.
 */
final class MyAccessController extends Controller
{
    public function __invoke(Request $request, ClientScopeResolver $scopes): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $tenantId = app(TenantContext::class)->tenantId();
        abort_if($tenantId === null, 403, 'No tenant context.');

        $role = $user->rolesInCurrentTenant()->filter(fn (Role $r) => (string) $r->tenant_id === $tenantId)->sortBy('id')->first();
        $preset = $role !== null ? (WorkspaceRolePresets::all()[(string) $role->slug] ?? null) : null;
        $sections = $user->accessSections();
        $reach = $scopes->reachableClientIds($user);

        return ApiResponse::success([
            'role' => $role === null ? null : [
                'slug' => (string) $role->slug,
                'name_ar' => $preset['ar'] ?? (string) $role->name,
                'name_en' => $preset['en'] ?? (string) $role->name,
            ],
            'is_owner' => (bool) $user->is_platform_admin || $user->hasRole(WorkspaceRolePresets::OWNER),
            'sections' => $sections,
            'all_sections' => array_map(fn (array $s) => ['key' => $s['key'], 'ar' => $s['ar'], 'en' => $s['en'], 'allowed' => in_array($s['key'], $sections, true)], AccessSections::all()),
            'clients' => ['all' => $reach === null, 'ids' => $reach === null ? [] : array_values(array_map('strval', $reach))],
        ], 'Access retrieved.');
    }
}
