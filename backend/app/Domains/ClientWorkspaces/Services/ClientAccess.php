<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Central authorization for the Client Command Center — the codebase-consistent equivalent of a policy
 * (the app authorizes inline via permissions rather than Gate policies). Every client action funnels
 * through here so isolation is enforced ONCE, fail-closed:
 *
 *  1. cross-tenant is impossible — ClientWorkspace is globally tenant-scoped (findOrFail → 404 elsewhere);
 *  2. a user without the required permission is denied (403);
 *  3. a user WITH the permission but WITHOUT access to that specific client is still denied (403),
 *     unless they hold `clients.view_all` (agency-wide visibility) or own the client.
 *
 * "Access to a client" = holds clients.view_all, OR is the client owner, OR is a granted team member.
 *
 * ADR 0002 adds a CEILING on top of that grant. When the active membership names specific clients —
 * an agency account manager responsible for three of them — those three are the most the request can
 * reach, whatever else would otherwise have allowed it. Ownership and team rows GRANT access; the
 * membership scope CAPS it, and the two are deliberately not merged: a person can be a client's owner
 * in one workspace and confined to a different set in another.
 *
 * The ceiling is fail-closed. A membership with no scope rows reaches no clients through it; only the
 * `clients.view_all` permission lifts the cap, and that is a positive grant rather than missing data.
 */
final class ClientAccess
{
    public function __construct(private readonly ClientScopeResolver $scopes) {}

    /** Resolve a client within the current tenant or 404 (global tenant scope makes cross-tenant a 404). */
    public function resolve(string $clientId): ClientWorkspace
    {
        return ClientWorkspace::findOrFail($clientId);
    }

    public function canViewAll(User $user): bool
    {
        return $user->hasPermission('clients.view_all');
    }

    public function canAccessClient(User $user, ClientWorkspace $client): bool
    {
        if ($this->canViewAll($user)) {
            return true;
        }

        /*
         * When the active membership NAMES clients, it is the whole answer — both the grant and the
         * limit. An agency account manager made responsible for three clients reaches those three
         * without needing a separate team row for each, and reaches nothing else even if they happen
         * to own it.
         *
         * A membership that names none falls through to the explicit grants below (ownership, team
         * roster), each a positive record. What an empty scope set must never mean is "every client",
         * and nothing here makes it so: only `clients.view_all` lifts the limit, and that is a
         * permission somebody granted.
         */
        $scoped = $this->scopes->reachableClientIds($user);
        if ($scoped !== null && $scoped !== []) {
            return in_array((string) $client->id, $scoped, true);
        }

        if ($client->owner_id !== null && (int) $client->owner_id === (int) $user->id) {
            return true;
        }

        return DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /** Assert the user may SEE this client at all (membership-gated). Aborts 403 otherwise. */
    public function assertView(User $user, ClientWorkspace $client): void
    {
        abort_unless($user->hasPermission('clients.view'), 403);
        abort_unless($this->canAccessClient($user, $client), 403);
    }

    /** Assert the user holds $permission AND has access to this client. Aborts 403 otherwise. */
    public function assert(User $user, string $permission, ClientWorkspace $client): void
    {
        abort_unless($user->hasPermission($permission), 403);
        abort_unless($this->canAccessClient($user, $client), 403);
    }

    /**
     * Restrict a ClientWorkspace query to the clients this user may see. A user with clients.view_all sees
     * the whole (tenant-scoped) portfolio; everyone else sees only owned or granted clients.
     *
     * @param  Builder<ClientWorkspace>  $query
     * @return Builder<ClientWorkspace>
     */
    public function restrictQuery(Builder $query, User $user): Builder
    {
        if ($this->canViewAll($user)) {
            return $query;
        }

        $memberIds = DB::table('client_workspace_user')
            ->where('user_id', $user->id)
            ->pluck('client_workspace_id');

        // A membership that names clients IS the list — it both grants and limits, so it replaces
        // the owner/team union rather than intersecting with it.
        $scoped = $this->scopes->reachableClientIds($user);
        if ($scoped !== null && $scoped !== []) {
            return $query->whereIn('id', $scoped);
        }

        return $query->where(function (Builder $q) use ($user, $memberIds): void {
            $q->where('owner_id', $user->id)->orWhereIn('id', $memberIds);
        });
    }
}
