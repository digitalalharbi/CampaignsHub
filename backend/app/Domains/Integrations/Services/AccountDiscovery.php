<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Illuminate\Support\Carbon;

/**
 * RUNTIME-100 §5 §32 — cataloguing what an authorisation can reach, and keeping that catalogue true.
 *
 * ## Why this is a service and not a private method on the OAuth callback
 *
 * It was a private method on the OAuth callback, which meant the ONLY way to refresh a catalogue was
 * to authorise again. That is how the live Snapchat connection ended up showing raw organisation
 * UUIDs where names belong: the 309 accounts were discovered before `parent_name` was recorded at
 * all, and the only offered route to a name was to send the customer back through a consent screen
 * for an authorisation that never lapsed.
 *
 * A token that still works can answer the same question again. Re-consenting to fix OUR missing
 * column is asking the customer to pay for our omission.
 *
 * ## Upsert, never replace
 *
 * Keyed on `(connection, external_id, account_type)` — the table's own unique key — so a refresh
 * updates what it already knows and adds what is new. Nothing is deleted: an account that has
 * disappeared from the provider's answer is MARKED (`access_lost_at`) and kept, because it may still
 * be bound to a project holding a year of history, and because «gone from this response» and «gone»
 * are not the same fact. A provider having a bad minute must not be able to erase a customer's
 * inventory.
 */
final class AccountDiscovery
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /**
     * Re-read this connection's ad accounts and bring the catalogue up to date.
     *
     * @return array{discovered:int, created:int, named:int, access_lost:int}
     */
    public function refresh(ProviderConnection $connection): array
    {
        $connector = $this->registry->get($connection->provider);

        if (! $connector instanceof ApiAdvertisingConnector) {
            return ['discovered' => 0, 'created' => 0, 'named' => 0, 'access_lost' => 0];
        }

        $accounts = $connector->withConnection($connection)->listAdAccounts();

        $created = 0;
        $named = 0;
        $seen = [];

        foreach ($accounts as $account) {
            $seen[] = (string) $account['external_id'];

            $existing = ExternalAccount::withoutGlobalScopes()
                ->where('provider_connection_id', $connection->getKey())
                ->where('external_id', $account['external_id'])
                ->where('account_type', 'ad_account')
                ->first();

            if ($existing === null) {
                $created++;
            } elseif ($existing->parent_name === null && ($account['parent_name'] ?? null) !== null) {
                // Counted so the interface can say «12 organisation names filled in» rather than
                // «refreshed», which tells somebody staring at UUIDs nothing about whether it worked.
                $named++;
            }

            ExternalAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'provider_connection_id' => $connection->getKey(),
                    'external_id' => $account['external_id'],
                    'account_type' => 'ad_account',
                ],
                [
                    'tenant_id' => $connection->tenant_id,
                    'client_workspace_id' => $connection->client_workspace_id,
                    'provider' => $connection->provider,
                    'name' => $account['name'],
                    'currency' => $account['currency'],
                    'timezone' => $account['timezone'],
                    'status' => $account['status'],
                    'parent_external_id' => $account['parent_external_id'] ?? null,
                    'parent_name' => $account['parent_name'] ?? null,
                    'discovered_at' => Carbon::now(),
                    // Reachable again. An account that came back is no longer lost, whatever it was
                    // last time — this is the only place that clears the mark.
                    'access_lost_at' => null,
                ],
            );
        }

        $accessLost = $this->markUnreachable($connection, $seen);

        $connection->forceFill(['last_health_check_at' => Carbon::now()])->save();

        return [
            'discovered' => count($accounts),
            'created' => $created,
            'named' => $named,
            'access_lost' => $accessLost,
        ];
    }

    /**
     * Accounts this authorisation used to reach and no longer does.
     *
     * RUNTIME-100 §32 — marked, never deleted. Deleting would take a bound account's history with
     * it, and a provider that answered with an empty list for one minute would wipe a customer's
     * entire inventory with no way back. A mark is reversible; a delete is not.
     *
     * Only meaningful when the provider actually answered: an empty response is far more often a
     * failed call than a customer who lost access to every account at once.
     *
     * @param  list<string>  $seenExternalIds
     */
    private function markUnreachable(ProviderConnection $connection, array $seenExternalIds): int
    {
        if ($seenExternalIds === []) {
            return 0;
        }

        return ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->whereNotIn('external_id', $seenExternalIds)
            ->whereNull('access_lost_at')
            ->update(['access_lost_at' => Carbon::now()]);
    }
}
