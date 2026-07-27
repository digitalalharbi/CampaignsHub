<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\ClientWorkspaces\Enums\ClientAccessRole;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Per-client team access on the client_workspace_user pivot. Enforced at the API (not just hidden in UI):
 * a member with no row cannot reach the client; removing the row denies access immediately. Guards:
 *   - a member must belong to the SAME tenant as the client (no cross-tenant grants);
 *   - project restriction (project_ids) must reference the client's own projects;
 *   - the LAST client_owner cannot be removed or demoted (a client always keeps an owner).
 */
final class ClientTeamService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  list<string>|null  $projectIds  null = all the client's projects
     */
    public function grant(ClientWorkspace $client, int $userId, string $accessRole, ?array $projectIds, User $actor): void
    {
        $this->assertValidRole($accessRole);
        $this->assertSameTenant($userId);
        $this->assertProjectsBelongToClient($client, $projectIds);

        DB::table('client_workspace_user')->updateOrInsert(
            ['client_workspace_id' => $client->id, 'user_id' => $userId],
            [
                'access_role' => $accessRole,
                'project_ids' => $projectIds !== null ? json_encode(array_values($projectIds)) : null,
                'granted_by' => $actor->id,
                'granted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
            ],
        );

        $this->audit->log('client.team_access_granted', 'client_workspace', (string) $client->id, null,
            ['user_id' => $userId, 'access_role' => $accessRole]);
    }

    /** @param  list<string>|null  $projectIds */
    public function updateAccess(ClientWorkspace $client, int $userId, ?string $accessRole, ?array $projectIds, User $actor): void
    {
        $current = DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)->where('user_id', $userId)->first();
        abort_if($current === null, 404, 'Member has no access to this client.');

        if ($accessRole !== null) {
            $this->assertValidRole($accessRole);
            // Demoting the last owner away from client_owner is blocked.
            if ($current->access_role === ClientAccessRole::ClientOwner->value && $accessRole !== ClientAccessRole::ClientOwner->value) {
                $this->assertNotLastOwner($client, $userId);
            }
        }
        if ($projectIds !== null) {
            $this->assertProjectsBelongToClient($client, $projectIds);
        }

        $update = ['updated_at' => Carbon::now()];
        if ($accessRole !== null) {
            $update['access_role'] = $accessRole;
        }
        if ($projectIds !== null) {
            // An empty allowlist means "restrict to nothing explicit" → store null (all projects) only when
            // the caller sends null; an explicit [] would be nonsensical, so treat [] as "all" too.
            $update['project_ids'] = $projectIds === [] ? null : json_encode(array_values($projectIds));
        }
        DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)->where('user_id', $userId)->update($update);

        $this->audit->log('client.team_access_updated', 'client_workspace', (string) $client->id, null,
            ['user_id' => $userId, 'access_role' => $accessRole]);
    }

    public function remove(ClientWorkspace $client, int $userId, User $actor): void
    {
        $row = DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)->where('user_id', $userId)->first();
        abort_if($row === null, 404, 'Member has no access to this client.');

        if ($row->access_role === ClientAccessRole::ClientOwner->value) {
            $this->assertNotLastOwner($client, $userId);
        }

        DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)->where('user_id', $userId)->delete();

        $this->audit->log('client.team_access_removed', 'client_workspace', (string) $client->id,
            ['user_id' => $userId], null);
    }

    private function assertValidRole(string $role): void
    {
        if (! in_array($role, ClientAccessRole::values(), true)) {
            throw ValidationException::withMessages(['access_role' => 'Invalid access role.']);
        }
    }

    private function assertSameTenant(int $userId): void
    {
        $ok = User::where('id', $userId)->where('tenant_id', (string) $this->tenant->tenantId())->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['user_id' => 'User must belong to this workspace.']);
        }
    }

    /** @param  list<string>|null  $projectIds */
    private function assertProjectsBelongToClient(ClientWorkspace $client, ?array $projectIds): void
    {
        if ($projectIds === null || $projectIds === []) {
            return;
        }
        $valid = DB::table('projects')->where('client_workspace_id', $client->id)
            ->whereIn('id', $projectIds)->count();
        if ($valid !== count(array_unique($projectIds))) {
            throw ValidationException::withMessages(['project_ids' => 'Projects must belong to this client.']);
        }
    }

    private function assertNotLastOwner(ClientWorkspace $client, int $excludingUserId): void
    {
        $otherOwners = DB::table('client_workspace_user')
            ->where('client_workspace_id', $client->id)
            ->where('access_role', ClientAccessRole::ClientOwner->value)
            ->where('user_id', '!=', $excludingUserId)->count();
        if ($otherOwners === 0) {
            throw ValidationException::withMessages(['access_role' => 'A client must keep at least one owner.']);
        }
    }
}
