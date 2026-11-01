<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which client spaces does this portal request reach, and which engine said so? (PORTAL-AUTH-001)
 *
 * During the cutover BOTH engines are live: the legacy OTP token cookie, and a Sanctum session over
 * a user with a `ClientPortal` membership. This is the one place that decides between them, so the
 * choice cannot drift between readers.
 *
 * **The membership wins when there is one**, because it is the engine the product is moving to and
 * the one every other portal already uses. The token is the fallback for sessions opened before the
 * cutover, which must keep working for their remaining lifetime — signing every client out mid-task
 * is not an acceptable migration step.
 *
 * `parity()` exists for the cutover decision itself: it returns what BOTH engines would answer, so a
 * test — and the operator — can refuse to retire the old one while any user or space disagrees.
 */
final class ClientPortalIdentity
{
    public function __construct(private readonly ClientPortalContacts $contacts) {}

    /**
     * The client-workspace ids this request may reach, and the engine that decided.
     *
     * @return array{ids: list<string>, engine: string}
     */
    public function reach(Request $request, ?ClientPortalToken $token): array
    {
        $membership = $this->membershipFor($request, $token);

        if ($membership !== null) {
            $ids = $membership->clientScopeIds();
            sort($ids);

            // A ClientPortal membership with no scope reaches NOTHING. It is never "everything" —
            // that would make a failed backfill row into a key to the whole agency.
            return ['ids' => $ids, 'engine' => 'membership'];
        }

        if ($token === null) {
            return ['ids' => [], 'engine' => 'none'];
        }

        $ids = $this->contacts->ownedWorkspaceIds($token);
        sort($ids);

        return ['ids' => $ids, 'engine' => 'token'];
    }

    /**
     * The projects this request is confined to, or null when it is not (CP-USERS-001).
     *
     * A client user may be limited to some of their client's projects — a marketing lead for one brand of
     * several. That ceiling lives on the membership as project scopes, and it is a HARD one: the client
     * space still resolves, but nothing inside it may be read beyond these projects. Null means «the whole
     * space», which only the membership engine can say deliberately (no project scopes) and which the
     * legacy token engine always says, because a token has never carried a project limit.
     *
     * @return list<string>|null
     */
    public function projectReach(Request $request, ?ClientPortalToken $token): ?array
    {
        $membership = $this->membershipFor($request, $token);

        if ($membership === null) {
            return null;
        }

        $ids = $membership->projectScopeIds();

        return $ids === [] ? null : array_values($ids);
    }

    /**
     * The client role that RESTRICTS this session — `client_viewer`, `client_approver` or `client_admin` — or
     * null when nothing restricts it (CP-USERS-001).
     *
     * Only a role a person CHOSE restricts anybody. The legacy contact engine has never had one (the
     * verified contact is the decision maker), and the memberships the migration and the seeders provisioned
     * (`invited_by` null) carry `client_viewer` only because a row needs some role: reading it as a
     * restriction would have turned every existing client who could reply and approve into a read-only
     * account on the day this shipped. A membership granted or accepted through the agency's client-users
     * flow records who granted it, and that is the one whose role is meant.
     */
    public function clientRole(Request $request, ?ClientPortalToken $token): ?string
    {
        if ($request->user() === null) {
            return null;
        }

        $membership = $this->membershipFor($request, $token);

        return $membership !== null && $membership->invited_by !== null ? (string) $membership->role : null;
    }

    /**
     * What each engine would answer for this request, side by side.
     *
     * The cutover is only safe when these agree for every user and every space. Comparing them at
     * runtime — rather than reasoning that they must match — is what makes "we lost nobody" a
     * measurement instead of a hope.
     *
     * @return array{membership: ?list<string>, token: ?list<string>, agree: bool}
     */
    public function parity(Request $request, ?ClientPortalToken $token): array
    {
        $membership = $this->membershipFor($request, $token);

        $fromMembership = null;
        if ($membership !== null) {
            $fromMembership = $membership->clientScopeIds();
            sort($fromMembership);
        }

        $fromToken = null;
        if ($token !== null) {
            $fromToken = $this->contacts->ownedWorkspaceIds($token);
            sort($fromToken);
        }

        // Only a real disagreement counts. One engine being absent is the normal state on either
        // side of the cutover, not a mismatch.
        $agree = $fromMembership === null || $fromToken === null || $fromMembership === $fromToken;

        return ['membership' => $fromMembership, 'token' => $fromToken, 'agree' => $agree];
    }

    /**
     * The signed-in user's client-portal membership for this tenant, if any.
     *
     * Resolved from the SESSION user, or — when only a legacy token is present — from the address
     * that token was issued to. The second path is what lets a pre-cutover session be served by the
     * new engine without the holder signing in again.
     */
    private function membershipFor(Request $request, ?ClientPortalToken $token): ?Membership
    {
        $user = $request->user();

        if ($user === null && $token?->contact_email !== null) {
            $user = User::query()->where('email', $token->contact_email)->first();
        }

        /*
         * A phone one-time code is as good a proof of identity as an email one, and was ignored here: such a
         * token carries no address, so the person's membership never resolved and the session fell back to the
         * legacy engine, which derives reach from request rows — the whole client, with neither the project
         * ceiling nor the read-only role the agency chose. The number must be one the account PROVED it holds, and
         * must name exactly one account (two accounts on one number is not an identity).
         */
        if ($user === null && $token?->contact_email === null && $token?->contact_phone !== null) {
            $holders = User::query()->where('phone', $token->contact_phone)->whereNotNull('phone_verified_at')->limit(2)->get();
            $user = $holders->count() === 1 ? $holders->first() : null;
        }

        if ($user === null) {
            return null;
        }

        $tenantId = $token?->tenant_id;

        return Membership::query()
            ->where('user_id', $user->getKey())
            ->where('portal', Portal::ClientPortal->value)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->active()
            ->with('scopes')
            ->first();
    }
}
