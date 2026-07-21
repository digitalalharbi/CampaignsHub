<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Establishes a Sandbox provider connection and discovers external accounts. Real providers stay
 * "Awaiting Credentials" until keys exist — only Sandbox creates a working connection here.
 */
final class EstablishSandboxConnection
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{connection: ProviderConnection, accounts: Collection<int, ExternalAccount>}
     */
    public function execute(string $scope = 'project_only', ?string $connectionName = null): array
    {
        return DB::transaction(function () use ($scope, $connectionName): array {
            $credential = new IntegrationCredential([
                'provider' => 'sandbox',
                'credential_scope' => $scope,
                'credential_type' => 'oauth',
                'status' => 'active',
                'created_by' => Auth::id(),
            ]);
            $credential->setPayload('sandbox-oauth-token-'.bin2hex(random_bytes(4)));
            $credential->save();

            $connection = ProviderConnection::create([
                'credential_id' => $credential->id,
                'provider' => 'sandbox',
                'connection_name' => $connectionName ?? 'Sandbox connection',
                'scope' => $scope,
                'external_owner_id' => 'sbx-owner-1',
                'scopes' => ['ads_read', 'ads_manage', 'insights_read'],
                'status' => 'connected',
                'last_health_check_at' => now(),
                'created_by' => Auth::id(),
            ]);

            // Discover a spread of account types so the full model is exercised.
            $sandbox = new SandboxAdvertisingConnector;
            $discovered = collect();
            foreach ($sandbox->listAdAccounts() as $acct) {
                $discovered->push($this->account($connection, 'ad_account', $acct['id'], $acct['name'], $acct['currency']));
            }
            $discovered->push($this->account($connection, 'pixel', 'sbx-pixel-1', 'Sandbox Pixel'));
            $discovered->push($this->account($connection, 'analytics_property', 'sbx-ga4-1', 'Sandbox GA4 Property'));
            $discovered->push($this->account($connection, 'ecommerce_store', 'sbx-store-1', 'Sandbox Store'));

            $this->audit->log(
                action: 'integration.connection.created',
                entityType: ProviderConnection::class,
                entityId: (string) $connection->id,
                after: ['provider' => 'sandbox', 'accounts' => $discovered->count()],
            );

            return ['connection' => $connection, 'accounts' => $discovered];
        });
    }

    private function account(ProviderConnection $connection, string $type, string $externalId, string $name, ?string $currency = null): ExternalAccount
    {
        return ExternalAccount::create([
            'provider_connection_id' => $connection->id,
            'provider' => 'sandbox',
            'account_type' => $type,
            'external_id' => $externalId,
            'name' => $name.' — Sandbox',
            'currency' => $currency,
            'timezone' => 'Asia/Riyadh',
            'status' => 'active',
            'metadata' => ['sandbox' => true],
        ]);
    }
}
