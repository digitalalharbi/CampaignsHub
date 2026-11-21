<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Deny by default holds for demo figures too: a row is read only through an active binding (`BoundAccountVisibility`). This is how a
 * world made of demo rows — the seeders, and `ReviewAccountProvisioner`, which runs on Production — selects the accounts those rows
 * name, exactly as an operator's selection would leave them, instead of the rule growing an exception for demo data.
 *
 * Two things differ from an operator's selection, and both exist because nothing real stands behind these accounts:
 *
 *   - `sync_enabled` is false, so `AccountAssignment::scopeToAssigned` keeps every sweep away from them;
 *   - a connection created here is `disconnected`, so no sweep of `connected` connections (discovery, token refresh, sync, structure)
 *     sends the placeholder credential to a real provider.
 *
 * Idempotent, and it never reactivates a binding somebody switched off.
 */
final class DemoAccountSelection
{
    private const PLACEHOLDER_CREDENTIAL = 'DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN';

    /** An account known only by the id its demo rows name: give it its account row, then select it. */
    public static function select(string $projectId, string $accountId, string $provider, string $name): void
    {
        $project = DB::table('projects')->where('id', $projectId)->first(['tenant_id', 'client_workspace_id']);

        if ($project === null) {
            return;
        }

        if (! ExternalAccount::withoutGlobalScopes()->whereKey($accountId)->exists()) {
            (new ExternalAccount)->forceFill([
                'id' => $accountId,
                'tenant_id' => $project->tenant_id,
                'client_workspace_id' => $project->client_workspace_id,
                'provider_connection_id' => self::connectionId((string) $project->tenant_id, $provider),
                'provider' => $provider,
                'account_type' => 'ad_account',
                'external_id' => 'demo-'.substr($accountId, 0, 13),
                'name' => $name,
                'status' => 'active',
                'metadata' => ['is_demo' => true],
            ])->save();
        }

        self::bind($projectId, $accountId, $provider);
    }

    /** Select an account that already has its row. */
    public static function bind(string $projectId, string $accountId, string $provider, string $purpose = 'advertising'): void
    {
        $project = DB::table('projects')->where('id', $projectId)->first(['tenant_id', 'client_workspace_id']);

        if ($project === null) {
            return;
        }

        $exists = DB::table('project_integration_bindings')
            ->where('project_id', $projectId)
            ->where('external_account_id', $accountId)
            ->where('purpose', $purpose)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('project_integration_bindings')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $project->tenant_id,
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $projectId,
            'external_account_id' => $accountId,
            'provider' => $provider,
            'purpose' => $purpose,
            'is_active' => true,
            'sync_enabled' => false,
            'settings' => json_encode(['is_demo' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** The tenant's connection for this provider, or a labelled one that nothing will ever sweep. */
    private static function connectionId(string $tenantId, string $provider): string
    {
        $existing = ProviderConnection::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('provider', $provider)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $credential = new IntegrationCredential;
        $credential->forceFill([
            'id' => self::uuid("cred:{$tenantId}:{$provider}"),
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'credential_scope' => 'project_only',
            'credential_type' => 'oauth',
            'status' => 'active',
        ]);
        $credential->setPayload(self::PLACEHOLDER_CREDENTIAL);
        $credential->save();

        $id = self::uuid("conn:{$tenantId}:{$provider}");
        (new ProviderConnection)->forceFill([
            'id' => $id,
            'tenant_id' => $tenantId,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => ucfirst($provider).' — بيانات تجريبية',
            'scope' => 'project_only',
            'status' => 'disconnected',
        ])->save();

        return $id;
    }

    private static function uuid(string $label): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'campaignshub-demo-account-selection:'.$label);
    }
}
