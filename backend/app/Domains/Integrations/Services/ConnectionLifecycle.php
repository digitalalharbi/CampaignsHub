<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Concerns\ProjectScope;
use Illuminate\Support\Facades\DB;

/**
 * INTEGRATION-LIFECYCLE-001 — the ONE way an authorisation is ended, for every provider kind (advertising, commerce, measurement).
 *
 * Ending a connection means: nothing reads through it any more (every project binding of its accounts is switched OFF, in every
 * project), the connection is marked revoked, and the secret it held is erased. It never deletes what was already read — the
 * accounts, the synced figures, the orders, and the project links all stay, so a client's history survives and comes back under
 * «Reconnect». The platform itself is not called: a platform's own «remove this app» is the merchant's/advertiser's to do.
 */
final class ConnectionLifecycle
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{disabled_bindings:int} */
    public function disconnect(ProviderConnection $connection, string $auditAction): array
    {
        $disabled = 0;
        DB::transaction(function () use ($connection, $auditAction, &$disabled): void {
            $ids = ExternalAccount::query()->where('provider_connection_id', $connection->getKey())->pluck('id');
            $disabled = ProjectIntegrationBinding::withoutGlobalScope(ProjectScope::class)
                ->whereIn('external_account_id', $ids)->where('is_active', true)->update(['is_active' => false]);
            $connection->update(['status' => 'revoked', 'last_error' => 'Disconnected by user']);
            if ($connection->credential_id !== null && ($credential = IntegrationCredential::query()->find($connection->credential_id)) !== null) {
                $credential->forceFill(['encrypted_payload' => '', 'status' => 'revoked'])->save();
            }
            $this->audit->log(action: $auditAction, entityType: ProviderConnection::class, entityId: (string) $connection->getKey(), after: ['provider' => $connection->provider, 'disabled_bindings' => $disabled]);
        });

        return ['disabled_bindings' => $disabled];
    }
}
