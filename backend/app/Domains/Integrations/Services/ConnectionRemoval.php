<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Concerns\ProjectScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INTEG-SOURCE-REMOVAL-001 — taking a source out of the Connection Hub, for good, without touching its history.
 *
 * ## What «remove» does
 *
 * 1. Disables EVERY project binding that uses one of the connection's accounts (all projects, outside the
 *    project scope on purpose — a binding left live in a project the operator cannot see keeps data flowing).
 * 2. Stops its syncs: every scheduler selects `status = connected`, so the row is stamped `revoked`.
 * 3. Clears the stored credential locally (the encrypted payload is overwritten with an empty object and the
 *    credential marked revoked). The provider is NOT called: no revocation endpoint is wired into this
 *    codebase, and one would be invented here. The grant can additionally be withdrawn in the provider's own
 *    settings; until then the provider still lists the app, but CampaignsHub no longer holds a token for it.
 * 4. Stamps `removed_at`, which is what the hub filters on.
 *
 * ## What it never does
 *
 * It deletes and moves NOTHING: `external_accounts`, `daily_metrics`, entity metrics, sync runs, reports,
 * ownership rows and the audit trail all stay exactly where they were. That is why this is a soft hide and
 * not a `delete()` — every one of those tables hangs off the connection with a cascading foreign key.
 *
 * ## Reconnecting afterwards
 *
 * `TokenVault::open` finds the same row (one connection per tenant, workspace and platform, so the accounts
 * and their history keep one identity) and RESTORES it: new credential, `connected`, `removed_at` cleared.
 * Bindings stay disabled — a reconnect is not a selection.
 */
final class ConnectionRemoval
{
    /** A connection nobody finished is «abandoned» after this long. */
    public const ABANDONED_AFTER_HOURS = 24;

    /** Tables that carry an `external_account_id`; a row in any of them means the connection is not empty. */
    private const REFERENCING = [
        'project_integration_bindings', 'daily_metrics', 'entity_daily_metrics', 'metric_sync_runs',
        'external_campaigns', 'external_entity_mappings',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{disabled_bindings:int} */
    public function remove(ProviderConnection $connection, string $reason = 'operator'): array
    {
        return DB::transaction(function () use ($connection, $reason): array {
            $accountIds = ExternalAccount::withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->pluck('id');

            $disabled = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
                ->whereIn('external_account_id', $accountIds)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            if ($connection->credential_id !== null) {
                $credential = IntegrationCredential::withoutGlobalScopes()->find($connection->credential_id);

                if ($credential !== null) {
                    $credential->forceFill([
                        'encrypted_payload' => '{}',
                        'status' => 'revoked',
                        'expires_at' => null,
                    ])->save();
                }
            }

            $connection->forceFill([
                'status' => 'revoked',
                'last_error' => 'Removed by user',
                'token_expires_at' => null,
                'removed_at' => Carbon::now(),
            ])->save();

            $this->audit->log(
                action: 'integration.connection.removed',
                entityType: ProviderConnection::class,
                entityId: (string) $connection->getKey(),
                after: ['disabled_bindings' => $disabled, 'reason' => $reason, 'provider' => (string) $connection->provider],
                tenantId: (string) $connection->tenant_id,
            );

            return ['disabled_bindings' => $disabled];
        });
    }

    /** True when the connection holds no selection, has never produced data, and is not already removed. */
    public function isUnconfirmed(ProviderConnection $connection): bool
    {
        if ($connection->removed_at !== null || $connection->last_successful_sync_at !== null) {
            return false;
        }

        $ids = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->pluck('id');

        if ($ids->isEmpty()) {
            return true;
        }

        $selected = ProjectIntegrationBinding::withoutGlobalScopes()
            ->whereIn('external_account_id', $ids)->where('is_active', true)->exists();

        $synced = ExternalAccount::withoutGlobalScopes()
            ->whereIn('id', $ids)->whereNotNull('last_synced_at')->exists();

        return ! $selected && ! $synced;
    }

    /** Created more than a day ago and still unconfirmed: started in the wizard and never finished. */
    public function isAbandoned(ProviderConnection $connection): bool
    {
        return $connection->created_at !== null
            && $connection->created_at->lt(Carbon::now()->subHours(self::ABANDONED_AFTER_HOURS))
            && $this->isUnconfirmed($connection);
    }

    /**
     * The proof the sweep needs: NO binding (even a disabled one) and NO metric, run or mapping row
     * references any account of this connection. Anything that ever held data is never swept.
     */
    public function isReferenced(ProviderConnection $connection): bool
    {
        $ids = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->pluck('id')->all();

        if ($ids === []) {
            return false;
        }

        foreach (self::REFERENCING as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'external_account_id')
                && DB::table($table)->whereIn('external_account_id', $ids)->exists()) {
                return true;
            }
        }

        return false;
    }
}
