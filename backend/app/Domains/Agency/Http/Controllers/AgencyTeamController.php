<?php

declare(strict_types=1);

namespace App\Domains\Agency\Http\Controllers;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Services\ClientAccess;
use App\Domains\Tenancy\Actions\ManageMembershipScopes;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Who is on the agency team, and which clients each of them may reach (ADR 0002, AGENCY-004).
 *
 * This is the operator surface for the scope rules that were settled earlier, and it keeps their
 * shape rather than flattening them into one "save" button:
 *
 *   add     — grants more, keeps what they had. Idempotent.
 *   remove  — withdraws ONE client.
 *   replace — the destructive one; the caller has to ask for it by name.
 *
 * Two isolation rules hold throughout, and both are enforced here rather than trusted from the body:
 *
 *   1. A membership may only be touched if it belongs to the ACTIVE tenant and the agency portal.
 *      An id from another tenant is refused as not-found, so probing reveals nothing.
 *   2. An operator can never grant a client they cannot themselves reach. Without this, a scoped
 *      account manager could widen their own colleague's access — or their own, via a second
 *      account — to the whole agency, and the ceiling would mean nothing.
 */
final class AgencyTeamController extends Controller
{
    public function __construct(
        private readonly ManageMembershipScopes $scopeManager,
        private readonly ClientScopeResolver $scopes,
        private readonly ClientAccess $access,
        private readonly TenantContext $tenants,
        private readonly MembershipContext $memberships,
    ) {}

    /** GET /api/v1/agency/team */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('users.view'), 403);

        $memberships = Membership::query()
            ->where('tenant_id', $this->tenants->tenantId())
            ->where('portal', Portal::Agency->value)
            ->with(['user', 'scopes'])
            ->orderBy('created_at')
            ->get();

        // Resolve ids to names once, over the whole page, rather than per membership.
        $names = $this->clientNames($memberships);
        $own = $this->memberships->membership();

        return ApiResponse::success([
            'members' => $memberships->map(fn (Membership $m) => $this->present($m, $names, $own))->all(),
            'can_manage' => (bool) $user->hasPermission('users.update'),
            // The clients THIS operator may hand out. A scoped manager sees only their own.
            'assignable_clients' => $this->assignableClients($request),
        ], 'Agency team.');
    }

    /**
     * POST /api/v1/agency/team/{membership}/scopes — body: { client_ids: [...] }.
     *
     * Widening. Re-sending the same client is a no-op, which is what makes re-inviting someone safe.
     */
    public function addScopes(Request $request, string $membership): JsonResponse
    {
        $target = $this->authorizeTarget($request, $membership);
        $ids = $this->validatedClientIds($request);

        $this->scopeManager->add($target, MembershipScope::TYPE_CLIENT, $ids);

        return $this->fresh($target, 'Client access granted.');
    }

    /** DELETE /api/v1/agency/team/{membership}/scopes/{client} — withdraws exactly one. */
    public function removeScope(Request $request, string $membership, string $client): JsonResponse
    {
        $target = $this->authorizeTarget($request, $membership);

        // Withdrawing is narrowing, so it needs no reachability check on the client being removed —
        // but the membership must still hold it, or this silently "succeeds" having done nothing.
        abort_unless(in_array($client, $target->clientScopeIds(), true), 404);

        $this->scopeManager->remove($target, MembershipScope::TYPE_CLIENT, $client);

        return $this->fresh($target, 'Client access withdrawn.');
    }

    /**
     * PUT /api/v1/agency/team/{membership}/scopes — body: { client_ids: [...] }.
     *
     * The destructive one. An empty list leaves the member reaching NOTHING; it does not mean
     * unrestricted, which is only ever the `clients.view_all` permission.
     */
    public function replaceScopes(Request $request, string $membership): JsonResponse
    {
        $target = $this->authorizeTarget($request, $membership);
        $ids = $this->validatedClientIds($request);

        // Replacing must not silently drop clients this operator cannot see: they would vanish from
        // the member's access as a side effect of an edit made without knowing they existed.
        $unreachable = array_values(array_diff($target->clientScopeIds(), $ids));
        foreach ($unreachable as $id) {
            abort_unless(
                $this->scopes->canReach($request->user(), $id),
                403,
                'This member holds a client outside your own access. Ask someone with wider access to change it.',
            );
        }

        $this->scopeManager->replace($target, MembershipScope::TYPE_CLIENT, $ids);

        return $this->fresh($target, 'Client access replaced.');
    }

    /**
     * The membership being edited, or an abort. Not-found rather than forbidden for anything outside
     * the active tenant, so an id from another agency yields no signal either way.
     */
    private function authorizeTarget(Request $request, string $membershipId): Membership
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('users.update'), 403);

        $target = Membership::query()
            ->whereKey($membershipId)
            ->where('tenant_id', $this->tenants->tenantId())
            ->where('portal', Portal::Agency->value)
            ->with('scopes')
            ->first();

        abort_if($target === null, 404);

        // Changing your own ceiling from inside it is self-promotion, whatever the role says.
        $own = $this->memberships->membership();
        abort_if($own !== null && $own->is($target), 403, 'You cannot change your own client access.');

        return $target;
    }

    /**
     * @return list<string>
     *
     * Every id is checked against the ACTOR's reach, not just against the tenant. This is the rule
     * that stops a scoped manager from granting the whole agency to a colleague.
     */
    private function validatedClientIds(Request $request): array
    {
        $data = $request->validate([
            'client_ids' => ['present', 'array'],
            'client_ids.*' => ['string', Rule::exists('client_workspaces', 'id')],
        ]);

        $ids = array_values(array_unique(array_map('strval', $data['client_ids'])));

        foreach ($ids as $id) {
            abort_unless(
                $this->scopes->canReach($request->user(), $id),
                403,
                'You can only grant clients you have access to yourself.',
            );
        }

        // A client from another tenant passes `exists` but must never be grantable here.
        $inTenant = ClientWorkspace::query()->whereIn('id', $ids)
            ->pluck('id')->map(fn ($id) => (string) $id)->all();

        abort_if(count($inTenant) !== count($ids), 404);

        return $ids;
    }

    private function fresh(Membership $membership, string $message): JsonResponse
    {
        $membership = $membership->refresh()->load(['user', 'scopes']);

        return ApiResponse::success(
            ['member' => $this->present($membership, $this->clientNames(collect([$membership])), $this->memberships->membership())],
            $message,
        );
    }

    /**
     * @param  Collection<int, Membership>  $memberships
     * @return array<string, string>
     */
    private function clientNames($memberships): array
    {
        $ids = $memberships->flatMap(fn (Membership $m) => $m->clientScopeIds())->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return ClientWorkspace::query()->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(string) $id => (string) $name])
            ->all();
    }

    /**
     * @param  array<string, string>  $names
     * @return array<string, mixed>
     */
    private function present(Membership $membership, array $names, ?Membership $own = null): array
    {
        $scopeIds = $membership->clientScopeIds();
        $member = $membership->user;

        return [
            'id' => (string) $membership->getKey(),
            'role' => $membership->role,
            'status' => $membership->status,
            'user' => [
                'id' => (string) ($member?->getKey() ?? ''),
                'name' => $member?->name,
                'email' => $member?->email,
            ],
            // A membership with no rows is NOT unrestricted — unrestricted is the permission below.
            'client_scope_ids' => $scopeIds,
            'clients' => array_map(
                // A client the reader cannot see is still counted, but not named: hiding it entirely
                // would make the member's access look narrower than it is.
                fn (string $id) => ['id' => $id, 'name' => $names[$id] ?? null],
                $scopeIds,
            ),
            'is_client_scoped' => $scopeIds !== [],
            // Marked so the UI never offers a control this caller's own request would be refused:
            // widening your own ceiling is self-promotion, and the endpoints say so with a 403.
            'is_self' => $own !== null && $own->is($membership),
            'has_unrestricted_permission' => (bool) $member?->hasPermission(ClientScopeResolver::ALL_CLIENTS),
        ];
    }

    /** @return list<array{id: string, name: string}> */
    private function assignableClients(Request $request): array
    {
        $query = ClientWorkspace::query()->whereNull('archived_at')->orderBy('name');
        $this->access->restrictQuery($query, $request->user());

        return $query->get(['id', 'name'])
            ->map(fn (ClientWorkspace $c) => ['id' => (string) $c->id, 'name' => (string) $c->name])
            ->all();
    }
}
