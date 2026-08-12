<?php

declare(strict_types=1);

namespace App\Domains\Legal\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Concerns\ProjectScope;
use Illuminate\Support\Facades\DB;

/**
 * LEGAL-DELETE-001 — when a workspace's data is deleted, the access granted to reach it goes too.
 *
 * Deleting our copy while a live OAuth token still points at somebody's advertising account is half a
 * deletion. The token was granted so that we could hold that data; once we may not hold it, the grant
 * has no purpose and keeping it is a standing permission nobody would renew if asked today.
 *
 * ## Why this is a service and not a loop in the controller
 *
 * The behaviour has to be identical to a person pressing Disconnect — same status, same disabled
 * bindings, same audit action — because an operator explaining what happened should be describing one
 * thing, not two that happen to look alike. Sharing the shape here is what keeps them from drifting.
 *
 * ## Fail closed on a missing tenant
 *
 * A request with no `tenant_id` revokes NOTHING. It is not a licence to sweep: a null tenant means we
 * never established whose workspace this was, and «revoke everything we cannot attribute» is how one
 * person's deletion disconnects a hundred other people's accounts.
 */
final class ConnectionRevocations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Revoke every live provider connection for one tenant and disable its bindings.
     *
     * @return int how many connections were revoked — 0 is a normal answer, not a failure
     */
    public function revokeForTenant(?string $tenantId): int
    {
        if ($tenantId === null || $tenantId === '') {
            return 0;
        }

        return DB::transaction(function () use ($tenantId): int {
            $connections = ProviderConnection::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'revoked')
                ->get();

            foreach ($connections as $connection) {
                $accountIds = ExternalAccount::withoutGlobalScopes()
                    ->where('provider_connection_id', $connection->id)
                    ->pluck('id');

                // Across ALL projects, deliberately outside the project scope: a deletion that left a
                // binding live in a project the operator could not see would leave data flowing.
                $affected = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
                    ->whereIn('external_account_id', $accountIds)
                    ->update(['is_active' => false]);

                $connection->forceFill([
                    'status' => 'revoked',
                    'last_error' => 'Revoked by a verified data deletion request',
                ])->save();

                $this->audit->log(
                    action: 'integration.connection.revoked',
                    entityType: ProviderConnection::class,
                    entityId: (string) $connection->id,
                    after: ['disabled_bindings' => $affected, 'reason' => 'data_deletion'],
                );
            }

            return $connections->count();
        });
    }
}
