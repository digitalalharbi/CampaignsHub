<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Identity\Services\InvitationService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Actions\ManageMembershipScopes;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CP-USERS-001 — who may open one client's portal, and how they are given the key.
 *
 * ## Two cases, one rule: never a second person for the same email
 *
 * An email that already belongs to a user is GRANTED the scoped membership directly: the account, its
 * password and its history stay exactly as they are, and the new access is added to it. An email that
 * belongs to nobody gets a secure, expiring invitation, and accepting it creates the account and proves the
 * address in one step — there is no second verification to click, and no way to reach the portal without
 * having done the first. Creating a user is never granting access; only this class and its invitation do.
 *
 * ## What a role is
 *
 * `client_viewer` reads. `client_approver` reads and acts (approves quotes, pays, replies). `client_admin`
 * does both and may manage who else reaches the portal — and is grantable only where the agency has
 * explicitly switched `team_admin` on for that workspace, never by default.
 */
final class ClientPortalUsers
{
    public const ROLES = ['client_viewer', 'client_approver', 'client_admin'];

    public function __construct(
        private readonly GrantMembership $grant,
        private readonly ManageMembershipScopes $scopes,
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
        private readonly ClientAccess $access,
    ) {}

    /**
     * @param  list<string>|null  $projectIds  null = the whole client; otherwise a hard ceiling
     * @return array{state: string, delivery_status: ?string, dev_link: ?string, user_id: ?int}
     */
    public function grantOrInvite(ClientWorkspace $workspace, string $email, string $role, ?array $projectIds, User $by): array
    {
        $email = mb_strtolower(trim($email));
        $this->guard($workspace, $role, $projectIds);

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null) {
            $this->assertNotChangingAccessElsewhere($user, $workspace, $role, $projectIds, $by);

            $membership = $this->grant->execute(new MembershipGrant(
                user: $user,
                tenant: Tenant::query()->findOrFail($workspace->tenant_id),
                portal: Portal::ClientPortal,
                role: $role,
                clientScopeIds: [(string) $workspace->getKey()],
                grantedBy: $by,
            ));
            // The ceiling is what the agency just chose, so it REPLACES whatever the membership had: an
            // additive grant would leave an earlier narrow ceiling in place when «the whole client» was asked
            // for, and silently keep an earlier wide one when a single project was. (Project scopes belong to
            // the membership, not to one client, so this holds for a person on several clients too.)
            $this->scopes->replace($membership, MembershipScope::TYPE_PROJECT, $projectIds === null ? [] : array_values($projectIds));
            // A repeated grant is a CHANGE, not a no-op: the new role replaces the old one.
            if ($membership->role !== $role) {
                $membership->update(['role' => $role]);
            }
            $this->audit->log('client_portal.user_granted', 'client_workspace', (string) $workspace->getKey(), null, ['user_id' => $user->id, 'role' => $role, 'projects' => $projectIds]);

            return ['state' => 'granted', 'delivery_status' => null, 'dev_link' => null, 'user_id' => $user->id];
        }

        $invite = $this->invitations->inviteToClientPortal($workspace, $email, $role, $projectIds, $by);
        $this->audit->log('client_portal.user_invited', 'client_workspace', (string) $workspace->getKey(), null, ['email' => $email, 'role' => $role, 'projects' => $projectIds]);

        return ['state' => 'invited', 'delivery_status' => $invite['delivery_status'], 'dev_link' => $invite['dev_link'], 'user_id' => null];
    }

    /**
     * The people who can open this client's portal, and what each is limited to.
     *
     * @return list<array<string, mixed>>
     */
    public function list(ClientWorkspace $workspace): array
    {
        $memberships = Membership::query()
            ->where('tenant_id', $workspace->tenant_id)->where('portal', Portal::ClientPortal->value)->active()
            ->whereHas('scopes', fn ($q) => $q->where('scope_type', MembershipScope::TYPE_CLIENT)->where('scope_id', (string) $workspace->getKey()))
            ->with('scopes')->get();
        $people = User::query()->whereIn('id', $memberships->pluck('user_id')->all())->get(['id', 'name', 'email'])->keyBy('id');

        $members = $memberships->map(fn (Membership $m): array => [
            'membership_id' => (string) $m->getKey(), 'user_id' => $m->user_id, 'name' => $people[$m->user_id]->name ?? null, 'email' => $people[$m->user_id]->email ?? null,
            'role' => $m->role, 'projects' => $m->projectScopeIds() ?: null, 'state' => 'active',
        ])->all();

        $pending = DB::table('workspace_invitations')->where('client_workspace_id', $workspace->getKey())->whereNull('accepted_at')
            ->where('expires_at', '>', now())->get(['id', 'email', 'role_slug', 'project_ids', 'expires_at'])
            ->map(fn ($i): array => ['invitation_id' => $i->id, 'email' => $i->email, 'role' => $i->role_slug, 'projects' => $i->project_ids ? json_decode($i->project_ids, true) : null, 'state' => 'invited', 'expires_at' => $i->expires_at])->all();

        return array_merge($members, $pending);
    }

    /** Take this client away from a person. Their other access, and the account itself, are untouched. */
    public function revoke(ClientWorkspace $workspace, string $membershipId): void
    {
        $membership = Membership::query()->where('tenant_id', $workspace->tenant_id)->where('portal', Portal::ClientPortal->value)->whereKey($membershipId)->firstOrFail();
        $clients = $membership->clientScopeIds();
        abort_unless(in_array((string) $workspace->getKey(), $clients, true), 404);

        DB::transaction(function () use ($membership, $workspace, $clients): void {
            if (count($clients) <= 1) {
                // Their only client: the membership has nothing left to reach, so it goes. (A membership
                // with no scope rows must never survive — absence of rows is not «everything».)
                MembershipScope::query()->where('membership_id', $membership->getKey())->delete();
                $membership->delete();
            } else {
                MembershipScope::query()->where('membership_id', $membership->getKey())
                    ->where('scope_type', MembershipScope::TYPE_CLIENT)->where('scope_id', (string) $workspace->getKey())->delete();
            }
        });
        $this->audit->log('client_portal.user_revoked', 'client_workspace', (string) $workspace->getKey(), null, ['membership_id' => $membershipId]);
    }

    /**
     * One membership per person per portal is SHARED across the clients it names, so its role and project ceiling
     * are not this client's to rewrite. A grant that would change either while the person also holds a client the
     * granting actor cannot reach would widen (or silently strip) their access there — made by someone with no
     * authority over that client. Asking for exactly what the person already holds is harmless and still adds this
     * client; an actor who reaches every client the person holds may change them, as before.
     *
     * The store keeps ONE role and ONE project list per person (not per client): a different role for a second
     * client needs a per-client model, which is a product decision, so until then it is refused rather than guessed.
     *
     * @param  list<string>|null  $projectIds
     */
    private function assertNotChangingAccessElsewhere(User $user, ClientWorkspace $workspace, string $role, ?array $projectIds, User $by): void
    {
        $existing = Membership::query()->where('user_id', $user->getKey())->where('tenant_id', $workspace->tenant_id)
            ->where('portal', Portal::ClientPortal->value)->with('scopes')->first();
        if ($existing === null) {
            return;
        }

        $others = array_values(array_diff($existing->clientScopeIds(), [(string) $workspace->getKey()]));
        if ($others === []) {
            return;
        }

        $held = $existing->projectScopeIds();
        $asked = array_values($projectIds ?? []);
        sort($held);
        sort($asked);
        if ($existing->role === $role && $held === $asked) {
            return;
        }

        foreach (ClientWorkspace::query()->whereIn('id', $others)->get() as $other) {
            if (! $this->access->canAccessClient($by, $other)) {
                throw ValidationException::withMessages(['role' => 'This person already has portal access to another client with a different role or project limit. Their role and projects are shared across clients, so they cannot be changed from here.']);
            }
        }
    }

    /** @param list<string>|null $projectIds */
    private function guard(ClientWorkspace $workspace, string $role, ?array $projectIds): void
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Unknown client role.']);
        }
        if ($role === 'client_admin' && ! (PortalVisibility::capabilitiesFor($workspace)['team_admin'] ?? false)) {
            throw ValidationException::withMessages(['role' => 'Client administrators are not enabled for this client.']);
        }
        if ($projectIds !== null && $projectIds !== []) {
            $valid = DB::table('projects')->where('client_workspace_id', $workspace->getKey())->whereIn('id', $projectIds)->count();
            if ($valid !== count(array_unique($projectIds))) {
                throw ValidationException::withMessages(['project_ids' => 'Projects must belong to this client.']);
            }
        }
    }
}
