<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
 */
final class ClientAccess
{
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

        return $query->where(function (Builder $q) use ($user, $memberIds): void {
            $q->where('owner_id', $user->id)->orWhereIn('id', $memberIds);
        });
    }
}
