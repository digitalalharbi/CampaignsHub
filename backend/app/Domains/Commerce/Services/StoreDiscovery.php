<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Providers\ApiCommerceConnector;
use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Support\Carbon;

/**
 * The first real round trip of a store connection: ask the platform which store the token reaches
 * and catalogue it. One place, for the OAuth callback and for a store claimed after a
 * platform-initiated install (SALLA-INSTALL-001).
 */
final class StoreDiscovery
{
    public function __construct(private readonly CommerceConnectorRegistry $registry) {}

    public function discover(ProviderConnection $connection): int
    {
        $connector = $this->registry->get($connection->provider);

        if (! $connector instanceof ApiCommerceConnector) {
            return 0;
        }

        $stores = $connector->withConnection($connection)->fetchStores();

        foreach ($stores as $store) {
            ExternalAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'provider_connection_id' => $connection->getKey(),
                    'external_id' => $store['external_id'],
                    'account_type' => 'store',
                ],
                [
                    'tenant_id' => $connection->tenant_id,
                    'client_workspace_id' => $connection->client_workspace_id,
                    'provider' => $connection->provider,
                    'name' => $store['name'],
                    'currency' => $store['currency'],
                    'timezone' => $store['timezone'],
                    'status' => $store['status'] === '' ? 'active' : $store['status'],
                    'metadata' => array_filter([
                        'domain' => $store['domain'],
                        'plan' => $store['plan'],
                        'discovered_at' => Carbon::now()->toIso8601String(),
                    ], static fn ($v) => $v !== null),
                    'last_synced_at' => null, // discovered, not synced — those are different facts
                ],
            );
        }

        $connection->forceFill(['last_health_check_at' => Carbon::now()])->save();

        return count($stores);
    }
}
