<?php

declare(strict_types=1);

namespace App\Domains\Access\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\AccessSections;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Bootstrap\Support\WorkspaceReadCache;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ACCESS-SECTIONS-001 — GET /api/v1/me/access: what the signed-in member may open in the bound workspace, answered by
 * the one resolver (`HasRoles::permissionKeys()` + the member's override rows + the client ceiling). The navigation
 * hides every section not listed; the server refuses them regardless (`EnforceSectionAccess` and each route's own
 * check). A change made in the access center shows on the next fetch, no re-login: the answer is cached for ten minutes per
 * person, but every write to the tables it is derived from abandons the cache ({@see WorkspaceReadCache}).
 */
final class MyAccessController extends Controller
{
    public function __invoke(Request $request, ClientScopeResolver $scopes): JsonResponse
    {
        return ApiResponse::success($this->payload($request, $scopes), 'Access retrieved.');
    }

    /**
     * The document itself, for `/me/access` and for `/bootstrap`.
     *
     * PERF-BOOTSTRAP-001 — filed per tenant, membership and person in {@see WorkspaceReadCache}, and abandoned the moment any
     * table it is derived from is written (roles, their permissions, a member's overrides, client scopes, memberships), so
     * «a change made in the access center shows on the next fetch» still holds. What a request is AUTHORISED with is never read
     * from this document: `hasPermission()` and the route gates resolve from the database on every request, as before.
     *
     * @return array<string, mixed>
     */
    public function payload(Request $request, ClientScopeResolver $scopes): array
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $tenantId = app(TenantContext::class)->tenantId();
        abort_if($tenantId === null, 403, 'No tenant context.');

        // The client ceiling belongs to a MEMBERSHIP (one person may hold an agency and an advertiser membership in one tenant).
        $membership = app(MembershipContext::class)->membership();

        return WorkspaceReadCache::remember(
            WorkspaceReadCache::ACCESS,
            $tenantId,
            fn (): array => $this->compute($user, $tenantId, $scopes),
            membershipId: $membership === null ? null : (string) $membership->getKey(),
            userId: $user->getKey(),
            // The platform flag changes what `is_owner` says and lives on `users`, which is far too busy a table to watch.
            variant: $user->is_platform_admin ? 'pa' : '',
        );
    }

    /** @return array<string, mixed> */
    private function compute(User $user, string $tenantId, ClientScopeResolver $scopes): array
    {
        $role = $user->rolesInCurrentTenant()->filter(fn (Role $r) => (string) $r->tenant_id === $tenantId)->sortBy('id')->first();
        $preset = $role !== null ? (WorkspaceRolePresets::all()[(string) $role->slug] ?? null) : null;
        $sections = $user->accessSections();
        $reach = $scopes->reachableClientIds($user);

        return [
            'role' => $role === null ? null : [
                'slug' => (string) $role->slug,
                'name_ar' => $preset['ar'] ?? (string) $role->name,
                'name_en' => $preset['en'] ?? (string) $role->name,
            ],
            'is_owner' => (bool) $user->is_platform_admin || $user->hasRole(WorkspaceRolePresets::OWNER),
            'sections' => $sections,
            'all_sections' => array_map(fn (array $s) => ['key' => $s['key'], 'ar' => $s['ar'], 'en' => $s['en'], 'allowed' => in_array($s['key'], $sections, true)], AccessSections::all()),
            'clients' => ['all' => $reach === null, 'ids' => $reach === null ? [] : array_values(array_map('strval', $reach))],
        ];
    }
}
