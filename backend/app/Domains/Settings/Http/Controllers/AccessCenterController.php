<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Services\AccessCenter;
use App\Domains\Access\Support\AccessCatalogue;
use App\Domains\Access\Support\AccessSections;
use App\Domains\Access\Support\WorkspaceRolePresets;
use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Services\ClientPortalUsers;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Notifications\Services\MemberNotices;
use App\Domains\Tenancy\Actions\ManageMembershipScopes;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ACCESS-CENTER-001 — «الفريق والنطاقات»: the one place every invitation and every member's access is decided.
 *
 * ## Walls
 *
 * - `settings.manage` for everything here, as on the rest of the team screen. Categories are the OWNER's alone.
 * - THIS tenant only: every lookup is by the bound tenant; another workspace's member, invitation or category is
 *   a 404, never a 403 that confirms it exists.
 * - Nobody grants what they do not hold, and only the owner hands out the team or the workspace (`AccessCenter`).
 * - Nobody edits their own access, and the owner's access is locked (it holds everything; a switch that could
 *   strip it would lock the workspace out of itself).
 * - Every change is audited with who, when, before, after and the optional «why».
 *
 * Client-portal invitations keep their own endpoint (`/app/clients/{client}/portal-users`) and their own three
 * roles; this screen lists them on the same board and resends them, and never changes what a portal can do.
 */
final class AccessCenterController extends Controller
{
    public function __construct(private readonly AccessCenter $access) {}

    /** GET /api/v1/settings/access */
    public function index(Request $request, ClientScopeResolver $scopes): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $categories = $this->access->categories($tenantId);
        $names = collect($categories)->keyBy('slug');

        $clientNames = DB::table('client_workspaces')->where('tenant_id', $tenantId)->whereNull('deleted_at')->pluck('name', 'id')->map(fn ($n) => (string) $n);
        $reach = $scopes->reachableClientIds($actor);
        $clients = $clientNames->filter(fn ($n, $id) => $reach === null || in_array((string) $id, $reach, true))
            ->map(fn ($n, $id) => ['id' => (string) $id, 'name' => $n])->values();

        return ApiResponse::success([
            'areas' => array_map(fn (array $a) => ['key' => $a['key'], 'ar' => $a['ar'], 'en' => $a['en']], AccessCatalogue::areas()),
            // ACCESS-SECTIONS-001 — the seven sections the owner switches per member (layer three).
            'sections' => array_map(fn (array $x) => ['key' => $x['key'], 'ar' => $x['ar'], 'en' => $x['en']], AccessSections::all()),
            'categories' => $categories,
            'members' => $this->members($tenantId, $actor, $names->all(), $clientNames->all()),
            'invitations' => $this->invitations($tenantId, $names->all(), $clientNames->all()),
            'revoked' => $this->revoked($tenantId),
            'clients' => $clients,
            'portal_roles' => ClientPortalUsers::ROLES,
            // Client scopes are an agency's idea: the scope control is drawn only where the workspace has clients to scope.
            'agency' => (string) Tenant::query()->whereKey($tenantId)->value('account_type') === 'agency',
            'me' => [
                'is_owner' => $this->access->isOwner($actor),
                'grantable' => $this->grantable($actor),
                'all_clients' => $reach === null,
            ],
        ], 'Access center retrieved.');
    }

    /** POST /api/v1/settings/access/categories — body: { name, from?, areas: {area: level}, all_clients? } */
    public function storeCategory(Request $request, AuditLogger $audit): JsonResponse
    {
        $actor = $this->manager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'from' => ['sometimes', 'nullable', 'string', 'max:80'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'all_clients' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $tenantId = $this->tenantId();
        $from = $data['from'] ?? null;
        $allClients = array_key_exists('all_clients', $data) ? (bool) $data['all_clients'] : ($from !== null ? null : false);

        $role = DB::transaction(fn (): Role => $this->access->createCategory($actor, $tenantId, trim($data['name']), $from, $data['areas'] ?? [], $allClients ?? false));
        $keys = $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();

        $audit->log('access.category_created', 'role', (string) $role->uuid, null, ['slug' => $role->slug, 'name' => $role->name, 'from' => $from, 'keys' => $keys], $data['reason'] ?? null);

        return ApiResponse::success(['slug' => $role->slug, 'name' => $role->name, 'areas' => AccessCatalogue::levelsFor($keys)], 'Category created.', status: 201);
    }

    /** PUT /api/v1/settings/access/categories/{slug} — body: { name?, areas?, all_clients? } — custom categories only. */
    public function updateCategory(Request $request, string $slug, AuditLogger $audit): JsonResponse
    {
        $actor = $this->manager($request);
        $this->access->assertOwner($actor);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:60'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'all_clients' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $tenantId = $this->tenantId();
        $role = $this->customCategory($tenantId, $slug);

        return DB::transaction(function () use ($actor, $role, $data, $tenantId, $audit): JsonResponse {
            $before = $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();
            $after = $this->access->shape($before, $data['areas'] ?? [], array_key_exists('all_clients', $data) ? (bool) $data['all_clients'] : null);
            $this->access->assertMayGrant($actor, array_values(array_diff($after, $before)), $after);

            if (isset($data['name']) && trim($data['name']) !== $role->name) {
                $this->access->assertNameFree($tenantId, $data['name'], (int) $role->id);
                $role->forceFill(['name' => trim($data['name'])])->save();
            }
            $role->permissions()->sync(Permission::query()->whereIn('key', $after)->pluck('id')->all());

            $audit->log('access.category_updated', 'role', (string) $role->uuid, ['slug' => $role->slug, 'keys' => $before], ['slug' => $role->slug, 'name' => $role->name, 'keys' => $after], $data['reason'] ?? null);

            return ApiResponse::success(['slug' => $role->slug, 'name' => $role->name, 'areas' => AccessCatalogue::levelsFor($after)], 'Category saved.');
        });
    }

    /** DELETE /api/v1/settings/access/categories/{slug} — custom, and only while nobody holds it or is invited into it. */
    public function destroyCategory(Request $request, string $slug, AuditLogger $audit): JsonResponse
    {
        $actor = $this->manager($request);
        $this->access->assertOwner($actor);
        $tenantId = $this->tenantId();
        $role = $this->customCategory($tenantId, $slug);

        $held = DB::table('role_user')->where('role_id', $role->id)->exists();
        $invited = DB::table('workspace_invitations')->where('tenant_id', $tenantId)->where('role_slug', $slug)->whereNull('accepted_at')->exists();
        abort_if($held || $invited, 422, 'This category is in use. Move its members and invitations first.');

        $audit->log('access.category_deleted', 'role', (string) $role->uuid, ['slug' => $role->slug, 'name' => $role->name, 'keys' => $role->permissions()->pluck('key')->all()], null, $request->input('reason'));
        $role->permissions()->detach();
        $role->delete();

        return ApiResponse::success(null, 'Category deleted.');
    }

    /**
     * POST /api/v1/settings/access/invitations — the team audience.
     * body: { email, category, areas?: {area: level}, client_ids?: [...], reason? }
     */
    public function invite(Request $request, AuditLogger $audit, InvitationService $invitations, ClientScopeResolver $scopes): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'category' => ['required', 'string', 'max:80'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'client_ids' => ['sometimes', 'array', 'max:200'],
            'client_ids.*' => ['string', 'distinct'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $this->assertKnownSections($data['sections'] ?? []);

        [$role, $overrides] = $this->decide($actor, $tenantId, $data['category'], $data['areas'] ?? [], $data['sections'] ?? []);
        $clientIds = $this->clientsWithinReach($actor, $tenantId, $data['client_ids'] ?? [], $scopes);

        $result = $invitations->invite(
            tenant: Tenant::query()->findOrFail($tenantId),
            email: $data['email'],
            roleSlug: (string) $role->slug,
            projectIds: null,
            invitedBy: $actor,
            accessOverrides: ['allow' => $overrides['allow'], 'deny' => $overrides['deny']],
            clientIds: $clientIds,
        );

        $audit->log('settings.team.invited', 'workspace_invitation', $result['id'], null, [
            'email' => mb_strtolower(trim($data['email'])), 'role' => $role->slug, 'allow' => $overrides['allow'], 'deny' => $overrides['deny'], 'client_ids' => $clientIds,
        ], $data['reason'] ?? null);

        return ApiResponse::success($result + ['areas' => AccessCatalogue::levelsFor($overrides['effective'])], 'Invitation sent.', status: 201);
    }

    /** PUT /api/v1/settings/access/invitations/{id} — change a pending team invitation's category, areas or clients. */
    public function updateInvitation(Request $request, string $invitation, AuditLogger $audit, ClientScopeResolver $scopes): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $row = DB::table('workspace_invitations')->where('tenant_id', $tenantId)->where('id', $invitation)->whereNull('accepted_at')->first();
        abort_if($row === null, 404, 'No such invitation.');
        abort_if($row->portal !== null, 422, 'A client-portal invitation is changed from the client\'s page.');

        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:80'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'client_ids' => ['sometimes', 'array', 'max:200'],
            'client_ids.*' => ['string', 'distinct'],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $this->assertKnownSections($data['sections'] ?? []);

        // The invitation as it stands must itself be within the actor's hands, or they could rewrite an owner's.
        $this->assertMayTouch($actor, $tenantId, (string) $row->role_slug, $this->storedOverrides($row));

        // Sections not named keep what the invitation already said.
        $sections = ($data['sections'] ?? []) + array_fill_keys(AccessSections::offIn($this->storedOverrides($row)['deny']), false);
        [$role, $overrides] = $this->decide($actor, $tenantId, $data['category'] ?? (string) $row->role_slug, $data['areas'] ?? [], $sections);
        $clientIds = array_key_exists('client_ids', $data)
            ? $this->clientsWithinReach($actor, $tenantId, $data['client_ids'], $scopes)
            : ($row->client_ids ? json_decode($row->client_ids, true) : null);

        $after = [
            'role_slug' => (string) $role->slug,
            'access_overrides' => $overrides['allow'] === [] && $overrides['deny'] === [] ? null : json_encode(['allow' => $overrides['allow'], 'deny' => $overrides['deny']]),
            'client_ids' => $clientIds ? json_encode(array_values($clientIds)) : null,
        ];
        DB::table('workspace_invitations')->where('id', $invitation)->update($after + ['updated_at' => Carbon::now()]);

        $audit->log('access.invitation_updated', 'workspace_invitation', $invitation,
            ['role' => $row->role_slug, 'overrides' => $this->storedOverrides($row), 'client_ids' => $row->client_ids ? json_decode($row->client_ids, true) : null],
            ['role' => $role->slug, 'overrides' => ['allow' => $overrides['allow'], 'deny' => $overrides['deny']], 'client_ids' => $clientIds],
            $data['reason'] ?? null);

        return ApiResponse::success(['id' => $invitation, 'areas' => AccessCatalogue::levelsFor($overrides['effective'])], 'Invitation saved.');
    }

    /** POST /api/v1/settings/access/invitations/{id}/resend — new link, fresh 72 hours; the old link stops working. */
    public function resend(Request $request, string $invitation, AuditLogger $audit, InvitationService $invitations): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $row = DB::table('workspace_invitations')->where('tenant_id', $tenantId)->where('id', $invitation)->whereNull('accepted_at')->first();
        abort_if($row === null, 404, 'No such invitation.');
        if ($row->portal === null) {
            $this->assertMayTouch($actor, $tenantId, (string) $row->role_slug, $this->storedOverrides($row));
        }

        $result = $invitations->resend($tenantId, $invitation, $actor);
        $audit->log('access.invitation_resent', 'workspace_invitation', $invitation, null, ['email' => $row->email, 'delivery_status' => $result['delivery_status']], $request->input('reason'));

        return ApiResponse::success($result, 'Invitation sent again.');
    }

    /** PUT /api/v1/settings/access/members/{user}/overrides — body: { areas: {area: level}, reason? } (an empty object clears). */
    public function updateOverrides(Request $request, string $user, AuditLogger $audit): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'areas' => ['present', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $target = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))->where('uuid', $user)->first();
        abort_if($target === null, 404);
        abort_if($target->id === $actor->id, 403, 'You cannot change your own access.');

        $role = Role::query()->where('tenant_id', $tenantId)
            ->whereIn('id', DB::table('role_user')->where('user_id', $target->id)->pluck('role_id'))
            ->orderBy('id')->first();
        abort_if($role === null, 422, 'This person holds no category in this workspace.');
        // Any owner role they hold locks them, whichever role happens to sort first.
        abort_if($target->hasRole(WorkspaceRolePresets::OWNER), 422, 'The workspace owner holds everything and cannot be narrowed.');

        $categoryKeys = $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();
        $before = $this->access->memberOverrides($tenantId, (int) $target->id);
        // What they hold today across every role they have here — the admin wall reads all of it.
        $currentKeys = $target->permissionKeys()->map(fn ($k) => (string) $k)->all();
        // Somebody who runs the team (an admin) is the owner's to change.
        abort_if(AccessCatalogue::isAdminSet($currentKeys) && ! $this->access->isOwner($actor), 403, 'Only the workspace owner can change an administrator\'s access.');

        $overrides = $this->access->overridesFor($categoryKeys, $data['areas']);
        $this->access->assertMayGrant($actor, array_values(array_diff($overrides['effective'], $currentKeys)), $overrides['effective']);

        DB::transaction(fn () => $this->access->writeOverrides($tenantId, (int) $target->id, $overrides['allow'], $overrides['deny'], (int) $actor->id));

        $audit->log('access.member_overrides_changed', 'user', (string) $target->uuid, ['category' => $role->slug] + $before,
            ['category' => $role->slug, 'allow' => $overrides['allow'], 'deny' => $overrides['deny']], $data['reason'] ?? null);

        return ApiResponse::success(['areas' => AccessCatalogue::levelsFor($overrides['effective'])], 'Access saved.');
    }

    /**
     * PUT /api/v1/settings/access/members/{user} — ACCESS-SECTIONS-001: edit a member AFTER the invitation, all three
     * layers in one save from the same screen: { category?, areas?: {area: level}, sections?: {section: bool},
     * client_ids?: [...], reason? }. Takes effect on the member's very next request (no re-login: nothing about
     * access lives in the session or the token). One audit row with the before and the after.
     */
    public function updateMember(Request $request, string $user, AuditLogger $audit, ClientScopeResolver $scopes, ManageMembershipScopes $scopeManager, MemberNotices $notices): JsonResponse
    {
        $actor = $this->manager($request);
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'category' => ['sometimes', 'string', 'max:80'],
            'areas' => ['sometimes', 'array'],
            'areas.*' => ['string', Rule::in(AccessCatalogue::LEVELS)],
            'sections' => ['sometimes', 'array'],
            'sections.*' => ['boolean'],
            'client_ids' => ['sometimes', 'array', 'max:200'],
            'client_ids.*' => ['string', 'distinct'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $this->assertKnownSections($data['sections'] ?? []);

        $target = User::query()->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'))->where('uuid', $user)->first();
        abort_if($target === null, 404);
        abort_if($target->id === $actor->id, 403, 'You cannot change your own access.');
        /*
         * OWNER-DOWNGRADE-001 — a workspace manager is never NARROWED (areas, sections or clients while they stay one), but
         * another workspace manager may MOVE them to a different category: a mistaken promotion must be reversible from
         * the screen. Only an owner (or the platform operator) may do it, and never to the last owner of the workspace.
         */
        if ($target->hasRole(WorkspaceRolePresets::OWNER)) {
            $leavingOwner = isset($data['category']) && $data['category'] !== WorkspaceRolePresets::OWNER;
            abort_unless($leavingOwner, 422, 'The workspace owner holds everything and cannot be narrowed.');
            abort_unless($this->access->isOwner($actor), 403, 'Only a workspace manager can change another workspace manager.');
            abort_if($this->ownerCount($tenantId) <= 1, 422, 'Cannot change the role of the last workspace manager.');
        }

        $membership = Membership::query()->where('tenant_id', $tenantId)->where('user_id', $target->id)->where('status', 'active')
            ->where('portal', '!=', Portal::ClientPortal->value)->first();
        abort_if($membership === null, 422, 'A client-portal member is changed from the client\'s page.');

        $currentRole = Role::query()->where('tenant_id', $tenantId)
            ->whereIn('id', DB::table('role_user')->where('user_id', $target->id)->pluck('role_id'))
            ->orderBy('id')->first();
        $currentKeys = $target->permissionKeys()->map(fn ($k) => (string) $k)->all();
        abort_if(AccessCatalogue::isAdminSet($currentKeys) && ! $this->access->isOwner($actor), 403, 'Only the workspace owner can change an administrator\'s access.');

        $before = $this->memberSnapshot($tenantId, $target, $membership, $currentRole);

        $slug = $data['category'] ?? ($currentRole !== null ? (string) $currentRole->slug : null);
        abort_if($slug === null, 422, 'Choose a category for this person.');
        $changedCategory = $currentRole === null || $slug !== (string) $currentRole->slug;
        $role = $changedCategory ? $this->access->assignableRole($actor, $tenantId, $slug) : $currentRole;
        $categoryKeys = $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all();
        if ($changedCategory) {
            $categoryKeys = $this->access->categoryKeys($tenantId, $slug) ?? [];
        }

        // Areas: named → recomputed against the category; not named → kept, unless the category changed (a new
        // category starts clean, as on the roles screen). Section switches belong to the person and are kept.
        $stored = $this->access->memberOverrides($tenantId, (int) $target->id);
        $markers = array_values(array_filter($stored['deny'], fn ($k) => str_starts_with((string) $k, AccessSections::PREFIX)));
        if (array_key_exists('areas', $data)) {
            $o = $this->access->overridesFor($categoryKeys, $data['areas']);
            $overrides = ['allow' => $o['allow'], 'deny' => [...$o['deny'], ...$markers]];
        } elseif ($changedCategory) {
            $overrides = ['allow' => [], 'deny' => $markers];
        } else {
            $overrides = $stored;
        }
        $final = AccessSections::apply($categoryKeys, $overrides, $data['sections'] ?? []);

        // Grant only what you hold; only the owner hands out the team.
        $this->access->assertMayGrant($actor, array_values(array_diff($final['effective'], $currentKeys)), $final['effective']);

        $clientIds = null;
        if (array_key_exists('client_ids', $data)) {
            $clientIds = $this->clientsWithinReach($actor, $tenantId, $data['client_ids'], $scopes) ?? [];
            // Never silently drop a client the actor cannot see themselves.
            $held = DB::table('membership_scopes')->where('membership_id', $membership->id)->where('scope_type', MembershipScope::TYPE_CLIENT)->pluck('scope_id')->map(fn ($i) => (string) $i)->all();
            foreach (array_diff($held, $clientIds) as $dropped) {
                abort_unless($scopes->canReach($actor, (string) $dropped), 403, 'This member holds a client outside your own access. Ask someone with wider access to change it.');
            }
        }

        DB::transaction(function () use ($target, $tenantId, $role, $changedCategory, $final, $actor, $clientIds, $membership, $scopeManager): void {
            if ($changedCategory) {
                $target->roles()->detach(Role::query()->where('tenant_id', $tenantId)->pluck('id')->all());
                $target->roles()->syncWithoutDetaching([$role->id]);
            }
            $this->access->writeOverrides($tenantId, (int) $target->id, $final['allow'], $final['deny'], (int) $actor->id);
            if ($clientIds !== null) {
                $scopeManager->replace($membership, MembershipScope::TYPE_CLIENT, $clientIds);
            }
        });

        $after = $this->memberSnapshot($tenantId, $target->fresh() ?? $target, $membership, $role);
        $audit->log('access.member_updated', 'user', (string) $target->uuid, $before, $after, $data['reason'] ?? null);

        if ($changedCategory) {
            $notices->roleChanged($target, Tenant::query()->findOrFail($tenantId), $currentRole !== null ? (string) $currentRole->name : '—', (string) $role->name, $actor);
        }

        return ApiResponse::success([
            'category' => (string) $role->slug,
            'areas' => AccessCatalogue::levelsFor($final['effective']),
            'sections' => AccessSections::statesFor($final['effective'], AccessSections::offIn($final['deny'])),
        ], 'Access saved.');
    }

    /**
     * GET /api/v1/settings/access/audit — ACCESS-SECTIONS-001: the workspace's recent permission changes, compact:
     * who, whom, when, and what changed (each field's before → after, lists as added / removed).
     */
    public function audit(Request $request): JsonResponse
    {
        $this->manager($request);
        $tenantId = $this->tenantId();
        $limit = min(100, max(1, (int) $request->query('limit', 30)));

        $rows = DB::table('audit_logs')->where('tenant_id', $tenantId)->whereIn('action', self::AUDITED)
            ->orderByDesc('created_at')->limit($limit)->get(['action', 'user_id', 'entity_type', 'entity_id', 'before', 'after', 'reason', 'created_at']);
        $who = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $targets = User::query()->whereIn('uuid', $rows->where('entity_type', 'user')->pluck('entity_id')->unique()->all())->get(['uuid', 'name', 'email'])->keyBy('uuid');
        $invites = DB::table('workspace_invitations')->where('tenant_id', $tenantId)->whereIn('id', $rows->where('entity_type', 'workspace_invitation')->pluck('entity_id')->unique()->all())->pluck('email', 'id');

        return ApiResponse::success(['entries' => $rows->map(function ($r) use ($who, $targets, $invites): array {
            $before = $r->before ? (array) json_decode((string) $r->before, true) : [];
            $after = $r->after ? (array) json_decode((string) $r->after, true) : [];
            $target = match ($r->entity_type) {
                'user' => isset($targets[$r->entity_id]) ? (string) ($targets[$r->entity_id]->name ?: $targets[$r->entity_id]->email) : null,
                'workspace_invitation' => (string) ($invites[$r->entity_id] ?? ($after['email'] ?? $before['email'] ?? '')),
                default => (string) ($after['name'] ?? $before['name'] ?? $after['slug'] ?? $before['slug'] ?? ''),
            };

            return [
                'action' => (string) $r->action,
                'by' => $r->user_id !== null ? (string) ($who[$r->user_id] ?? '') : null,
                'target' => $target,
                'at' => Carbon::parse($r->created_at)->toIso8601String(),
                'reason' => $r->reason,
                'changes' => self::diff($before, $after),
            ];
        })->values()], 'Access log retrieved.');
    }

    /** The actions the access log lists: every change to who may do what. */
    private const AUDITED = [
        'access.member_updated', 'access.member_overrides_changed', 'settings.team.role_changed', 'settings.team.invited',
        'access.invitation_updated', 'settings.team.invitation_revoked', 'access.category_created', 'access.category_updated',
        'access.category_deleted', 'settings.team.removed', 'settings.team.disabled', 'settings.team.enabled', 'settings.roles.updated',
    ];

    /**
     * Field by field: scalars as from → to, lists as added / removed. Unchanged fields are left out.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array<string, mixed>>
     */
    private static function diff(array $before, array $after): array
    {
        $out = [];
        foreach (array_values(array_unique([...array_keys($before), ...array_keys($after)])) as $field) {
            $b = $before[$field] ?? null;
            $a = $after[$field] ?? null;
            if ($b === $a) {
                continue;
            }
            if ((is_array($b) || $b === null) && (is_array($a) || $a === null) && array_is_list($b ?? []) && array_is_list($a ?? [])) {
                $added = array_values(array_diff(array_map('strval', $a ?? []), array_map('strval', $b ?? [])));
                $removed = array_values(array_diff(array_map('strval', $b ?? []), array_map('strval', $a ?? [])));
                if ($added !== [] || $removed !== []) {
                    $out[] = ['field' => (string) $field, 'added' => $added, 'removed' => $removed];
                }

                continue;
            }
            $out[] = ['field' => (string) $field, 'from' => is_scalar($b) ? $b : ($b === null ? null : json_encode($b)), 'to' => is_scalar($a) ? $a : ($a === null ? null : json_encode($a))];
        }

        return $out;
    }

    /**
     * A member's three layers as one comparable record — what the audit row stores before and after.
     *
     * @return array<string, mixed>
     */
    private function memberSnapshot(string $tenantId, User $target, Membership $membership, ?Role $role): array
    {
        $o = $this->access->memberOverrides($tenantId, (int) $target->id);
        $keys = $role !== null ? $role->permissions()->pluck('key')->map(fn ($k) => (string) $k)->all() : [];
        $effective = array_values(array_diff(array_unique([...$keys, ...$o['allow']]), $o['deny']));
        $states = AccessSections::statesFor($effective, AccessSections::offIn($o['deny']));

        return [
            'category' => $role?->slug,
            'sections' => array_keys(array_filter($states)),
            'client_ids' => DB::table('membership_scopes')->where('membership_id', $membership->id)->where('scope_type', MembershipScope::TYPE_CLIENT)
                ->orderBy('scope_id')->pluck('scope_id')->map(fn ($i) => (string) $i)->all(),
            'allow' => $o['allow'],
            'deny' => array_values(array_filter($o['deny'], fn ($k) => ! str_starts_with((string) $k, AccessSections::PREFIX))),
        ];
    }

    /** @param array<string, mixed> $sections */
    private function assertKnownSections(array $sections): void
    {
        $unknown = array_diff(array_keys($sections), AccessSections::keys());
        abort_if($unknown !== [], 422, 'Unknown section.');
    }

    /** GET /api/v1/settings/access/history?type=member|invitation|category&id=… — who changed what, when and why. */
    public function history(Request $request): JsonResponse
    {
        $this->manager($request);
        $tenantId = $this->tenantId();
        $data = $request->validate([
            'type' => ['required', Rule::in(['member', 'invitation', 'category'])],
            'id' => ['required', 'string', 'max:80'],
        ]);
        $entityType = ['member' => 'user', 'invitation' => 'workspace_invitation', 'category' => 'role'][$data['type']];
        $entityId = $data['id'];
        if ($data['type'] === 'category') {
            $entityId = (string) Role::query()->where('tenant_id', $tenantId)->where('slug', $data['id'])->value('uuid');
        }

        $rows = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->orderByDesc('created_at')->limit(30)->get(['action', 'user_id', 'before', 'after', 'reason', 'created_at']);
        $who = User::query()->whereIn('id', $rows->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');

        return ApiResponse::success(['entries' => $rows->map(fn ($r) => [
            'action' => (string) $r->action,
            'by' => $r->user_id !== null ? (string) ($who[$r->user_id] ?? '') : null,
            'at' => Carbon::parse($r->created_at)->toIso8601String(),
            'reason' => $r->reason,
            'before' => $r->before ? json_decode((string) $r->before, true) : null,
            'after' => $r->after ? json_decode((string) $r->after, true) : null,
        ])->values()], 'History retrieved.');
    }

    /**
     * The category and the overrides for one person, both checked: grant only what you hold; only the owner hands
     * out the team.
     *
     * @param  array<string, string>  $areas
     * @param  array<string, bool>  $sections
     * @return array{0: Role, 1: array{allow: list<string>, deny: list<string>, effective: list<string>}}
     */
    private function decide(User $actor, string $tenantId, string $category, array $areas, array $sections = []): array
    {
        $categoryKeys = $this->access->categoryKeys($tenantId, $category);
        abort_if($categoryKeys === null, 422, 'Unknown category for this workspace.');

        $overrides = $this->access->overridesFor($categoryKeys, $areas);
        // ACCESS-SECTIONS-001 — sections off become `section:<key>` deny rows; on where the category lacks it, its reading key.
        $overrides = AccessSections::apply($categoryKeys, $overrides, $sections);
        // Every key the person will hold must be the actor's to give — the category's and the switched-on ones.
        $this->access->assertMayGrant($actor, $overrides['effective'], $overrides['effective']);

        return [$this->access->assignableRole($actor, $tenantId, $category), $overrides];
    }

    /** @param array{allow: list<string>, deny: list<string>} $overrides */
    private function assertMayTouch(User $actor, string $tenantId, string $slug, array $overrides): void
    {
        $keys = $this->access->categoryKeys($tenantId, $slug) ?? [];
        $keys = array_values(array_diff(array_unique([...$keys, ...$overrides['allow']]), $overrides['deny']));
        $this->access->assertMayGrant($actor, $keys, $keys);
    }

    /** @return array{allow: list<string>, deny: list<string>} */
    private function storedOverrides(object $row): array
    {
        $o = isset($row->access_overrides) && $row->access_overrides ? (array) json_decode((string) $row->access_overrides, true) : [];

        return ['allow' => array_values((array) ($o['allow'] ?? [])), 'deny' => array_values((array) ($o['deny'] ?? []))];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>|null
     */
    private function clientsWithinReach(User $actor, string $tenantId, array $ids, ClientScopeResolver $scopes): ?array
    {
        if ($ids === []) {
            return null;
        }
        $inTenant = DB::table('client_workspaces')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $ids)->pluck('id')->map(fn ($i) => (string) $i)->all();
        abort_if(count($inTenant) !== count($ids), 404, 'No such client.');

        $reach = $scopes->reachableClientIds($actor);
        abort_if($reach !== null && array_diff($ids, $reach) !== [], 403, 'You can only share clients you reach yourself.');

        return array_values($ids);
    }

    /**
     * The highest level of each area the actor may switch on for somebody else.
     *
     * @return array<string, string>
     */
    private function grantable(User $actor): array
    {
        $held = $actor->is_platform_admin ? AccessCatalogue::switchableKeys() : WorkspaceRolePresets::heldBy($actor);
        $owner = $this->access->isOwner($actor);
        $out = [];
        foreach (AccessCatalogue::areas() as $area) {
            $view = AccessCatalogue::existing($area['view']);
            $manage = AccessCatalogue::existing([...$area['view'], ...$area['manage']]);
            $out[$area['key']] = (array_diff($manage, $held) === [] && ($owner || ! AccessCatalogue::isAdminSet($manage)))
                ? AccessCatalogue::MANAGE
                : (array_diff($view, $held) === [] ? AccessCatalogue::VIEW : AccessCatalogue::NONE);
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $categories
     * @param  array<string, string>  $clientNames
     * @return list<array<string, mixed>>
     */
    private function members(string $tenantId, User $actor, array $categories, array $clientNames): array
    {
        $memberships = DB::table('memberships')->where('tenant_id', $tenantId)->where('status', 'active')->get(['id', 'user_id', 'portal', 'role']);
        $users = User::query()->whereIn('id', $memberships->pluck('user_id')->unique()->all())->get(['id', 'uuid', 'name', 'email', 'disabled_at', 'last_login_at']);
        $scopes = DB::table('membership_scopes')->whereIn('membership_id', $memberships->pluck('id')->all())->where('scope_type', MembershipScope::TYPE_CLIENT)->get(['membership_id', 'scope_id'])->groupBy('membership_id');
        $roles = Role::query()->where('tenant_id', $tenantId)->with('permissions:id,key')->get()->keyBy('id');
        $held = DB::table('role_user')->whereIn('role_id', $roles->keys()->all())->get(['user_id', 'role_id'])->groupBy('user_id');
        $overrides = DB::table('member_access_overrides')->where('tenant_id', $tenantId)->get(['user_id', 'permission_key', 'effect'])->groupBy('user_id');

        return $users->map(function (User $u) use ($memberships, $scopes, $roles, $held, $overrides, $categories, $clientNames, $actor): array {
            $mine = $memberships->where('user_id', $u->id);
            $staff = $mine->firstWhere('portal', '!=', Portal::ClientPortal->value);
            $membership = $staff ?? $mine->first();
            $audience = $staff !== null ? 'team' : 'client';
            $clientIds = collect($scopes->get($membership->id, []))->pluck('scope_id')->map(fn ($i) => (string) $i)->all();

            $role = collect($held->get($u->id, []))->map(fn ($r) => $roles->get($r->role_id))->filter()->sortBy('id')->first();
            $o = collect($overrides->get($u->id, []));
            $allow = $o->where('effect', 'allow')->pluck('permission_key')->all();
            $deny = $o->where('effect', 'deny')->pluck('permission_key')->all();
            $keys = $role !== null ? $role->permissions->pluck('key')->map(fn ($k) => (string) $k)->all() : [];
            $effective = array_values(array_diff(array_unique([...$keys, ...$allow]), $deny));
            $slug = $audience === 'client' ? (string) $membership->role : ($role !== null ? (string) $role->slug : null);

            return [
                'id' => (string) $u->uuid,
                'membership_id' => (string) $membership->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'audience' => $audience,
                'category' => $slug,
                'category_name_ar' => $audience === 'team' ? ($categories[$slug]['name_ar'] ?? ($role !== null ? (string) $role->name : null)) : null,
                'category_name_en' => $audience === 'team' ? ($categories[$slug]['name_en'] ?? ($role !== null ? (string) $role->name : null)) : null,
                'is_owner' => $audience === 'team' && collect($held->get($u->id, []))->contains(fn ($r) => $roles->get($r->role_id)?->slug === WorkspaceRolePresets::OWNER),
                'is_self' => $u->id === $actor->id,
                'areas' => $audience === 'team' ? AccessCatalogue::levelsFor($effective) : null,
                'sections' => $audience === 'team' ? AccessSections::statesFor($effective, AccessSections::offIn($deny)) : null,
                'overrides' => ['allow' => count($allow), 'deny' => count($deny) - count(AccessSections::offIn($deny))],
                'override_areas' => array_values(array_unique(array_filter(array_map(fn ($k) => AccessCatalogue::areaOf((string) $k), [...$allow, ...$deny])))),
                'scope' => [
                    'all' => $audience === 'team' && $clientIds === [] && in_array('clients.view_all', $effective, true),
                    'clients' => array_values(array_map(fn ($id) => ['id' => $id, 'name' => $clientNames[$id] ?? '—'], $clientIds)),
                ],
                'status' => $u->disabled_at !== null ? 'disabled' : 'active',
                'last_login_at' => $u->last_login_at !== null ? Carbon::parse($u->last_login_at)->toIso8601String() : null,
            ];
        })->sortBy(fn ($m) => [$m['audience'] === 'team' ? 0 : 1, $m['is_owner'] ? 0 : 1, mb_strtolower($m['name'])])->values()->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $categories
     * @param  array<string, string>  $clientNames
     * @return list<array<string, mixed>>
     */
    private function invitations(string $tenantId, array $categories, array $clientNames): array
    {
        return DB::table('workspace_invitations')->where('tenant_id', $tenantId)->whereNull('accepted_at')->orderByDesc('created_at')->limit(200)
            ->get(['id', 'email', 'role_slug', 'portal', 'client_workspace_id', 'client_ids', 'access_overrides', 'delivery_status', 'expires_at', 'created_at'])
            ->map(function (object $i) use ($categories, $clientNames, $tenantId): array {
                $client = $i->portal !== null;
                $o = $this->storedOverrides($i);
                $ids = $client ? [(string) $i->client_workspace_id] : ($i->client_ids ? array_map('strval', (array) json_decode((string) $i->client_ids, true)) : []);
                $cat = $categories[(string) $i->role_slug] ?? null;
                $keys = $client ? [] : ($this->access->categoryKeys($tenantId, (string) $i->role_slug) ?? []);
                $effective = array_values(array_diff(array_unique([...$keys, ...$o['allow']]), $o['deny']));

                return [
                    'id' => (string) $i->id,
                    'email' => (string) $i->email,
                    'audience' => $client ? 'client' : 'team',
                    'category' => (string) $i->role_slug,
                    'category_name_ar' => $cat['name_ar'] ?? null,
                    'category_name_en' => $cat['name_en'] ?? null,
                    'areas' => $client ? null : AccessCatalogue::levelsFor($effective),
                    'sections' => $client ? null : AccessSections::statesFor($effective, AccessSections::offIn($o['deny'])),
                    'overrides' => ['allow' => count($o['allow']), 'deny' => count($o['deny']) - count(AccessSections::offIn($o['deny']))],
                    'override_areas' => array_values(array_unique(array_filter(array_map(fn ($k) => AccessCatalogue::areaOf((string) $k), [...$o['allow'], ...$o['deny']])))),
                    'scope' => ['all' => false, 'clients' => array_values(array_map(fn ($id) => ['id' => $id, 'name' => $clientNames[$id] ?? '—'], $ids))],
                    'status' => Carbon::parse($i->expires_at)->isPast() ? 'expired' : 'pending',
                    'delivery_status' => (string) $i->delivery_status,
                    'expires_at' => Carbon::parse($i->expires_at)->toIso8601String(),
                    'created_at' => Carbon::parse($i->created_at)->toIso8601String(),
                ];
            })->values()->all();
    }

    /**
     * Withdrawn invitations are deleted (the token must die with the row — TEAM-INVITE-001), so the board reads
     * them back from the audit trail: the last 30 days, at most 20.
     *
     * @return list<array{email: string, at: string}>
     */
    private function revoked(string $tenantId): array
    {
        return DB::table('audit_logs')->where('tenant_id', $tenantId)->where('action', 'settings.team.invitation_revoked')
            ->where('created_at', '>=', Carbon::now()->subDays(30))->orderByDesc('created_at')->limit(20)->get(['entity_id', 'before', 'created_at'])
            ->map(fn ($r) => [
                'id' => (string) $r->entity_id,
                'email' => (string) (((array) json_decode((string) $r->before, true))['email'] ?? ''),
                'at' => Carbon::parse($r->created_at)->toIso8601String(),
            ])->values()->all();
    }

    private function customCategory(string $tenantId, string $slug): Role
    {
        $role = Role::query()->where('tenant_id', $tenantId)->where('slug', $slug)->first();
        abort_if($role === null, 404);
        abort_if($role->is_system || WorkspaceRolePresets::isPreset($slug), 422, 'A built-in category is changed from the roles screen; duplicate it to make your own.');

        return $role;
    }

    private function manager(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor !== null && $actor->hasPermission('settings.manage'), 403);

        return $actor;
    }

    /** Distinct active workspace managers of THIS tenant (same count as the team screen's last-owner guard). */
    private function ownerCount(string $tenantId): int
    {
        return DB::table('users')
            ->join('role_user', 'role_user.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('memberships', 'memberships.user_id', '=', 'users.id')
            ->where('memberships.tenant_id', $tenantId)
            ->where('memberships.status', 'active')
            ->where('roles.slug', WorkspaceRolePresets::OWNER)
            ->where('roles.tenant_id', $tenantId)
            ->distinct('users.id')
            ->count('users.id');
    }

    private function tenantId(): string
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return $id;
    }
}
