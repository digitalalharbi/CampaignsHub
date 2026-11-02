<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Support\ProviderErrorText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
     * Why the latest discovery produced no list — named, not guessed.
     *
     * GADS-STALE-PICKER-001. The reason is for the reader, so it has to separate the cases that lead to
     * different actions. A provider that refuses THIS identity on THIS customer is not a provider whose
     * project lacks approval, and neither is a network fault.
     *
     * Anything unrecognised stays `discovery_failed`. A reason invented for an error nobody has read is
     * how a product ends up explaining the wrong thing confidently — the failure this row is named for.
     */
    private function classify(\Throwable $e): string
    {
        $said = $e->getMessage();

        return match (true) {
            str_contains($said, 'USER_PERMISSION_DENIED') => 'provider_permission_denied',
            str_contains($said, 'CUSTOMER_NOT_ENABLED') => 'provider_customer_not_enabled',
            str_contains($said, 'NOT_ADS_USER') => 'provider_identity_not_an_ads_user',
            str_contains($said, 'CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION'),
            str_contains($said, 'DEVELOPER_TOKEN_NOT_APPROVED'),
            str_contains($said, 'ACTION_NOT_PERMITTED') => 'provider_project_not_approved',
            default => 'discovery_failed',
        };
    }

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

        /*
         * GADS-STALE-PICKER-001 — the outcome is recorded whichever way it goes.
         *
         * A refusal used to leave no trace on the connection: the exception propagated, the account rows
         * stayed exactly as a previous permitted discovery had left them, and `ConnectionWizardState`
         * counted them as currently selectable. That is how one screen came to say «0 ad accounts» beside
         * «1 account available — Finish selecting accounts».
         *
         * The rows are still left alone — a temporary refusal must not unbind work an operator already
         * did — so what changes is that the CONNECTION now knows the latest attempt failed, and the count
         * of «currently available» can stop trusting rows that predate it.
         *
         * Rethrown, because the caller still has to handle the failure; this only stops it being silent.
         */
        $connection->forceFill(['last_discovery_attempted_at' => Carbon::now()])->save();

        try {
            // The BOUND connector is the one that read — and the one that holds what it could not read.
            $connector = $connector->withConnection($connection);
            $accounts = $connector->listAdAccounts();
        } catch (\Throwable $e) {
            $connection->forceFill([
                'discovery_blocked_reason' => $this->classify($e),
                'last_error' => ProviderErrorText::forStorage($e->getMessage()),
            ])->save();

            throw $e;
        }

        $created = 0;
        $named = 0;
        $seen = [];
        $now = Carbon::now();

        /*
         * PERF-INTEGRATIONS-001 — the connection's rows are read ONCE, not once per discovered account.
         *
         * This asked for each account, then upserted it: a select and an update for every one of the 565
         * accounts of a large organisation, inside the OAuth callback request, and an update even when
         * nothing about the account had changed. Now one read, one bulk insert per 200 new accounts, one
         * bulk touch for the unchanged, and a row of its own only for an account whose name, currency,
         * timezone, status or organisation actually changed.
         */
        $existing = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->get()
            ->keyBy(fn (ExternalAccount $row): string => (string) $row->external_id);

        $discovered = [];
        foreach ($accounts as $account) {
            $discovered[(string) $account['external_id']] = $account;
        }

        $inserts = [];
        $unchanged = [];

        foreach ($discovered as $externalId => $account) {
            $seen[] = $externalId;

            $fields = [
                'tenant_id' => $connection->tenant_id,
                'client_workspace_id' => $connection->client_workspace_id,
                'provider' => $connection->provider,
                'name' => $account['name'],
                'currency' => $account['currency'],
                'timezone' => $account['timezone'],
                'status' => $account['status'],
                'parent_external_id' => $account['parent_external_id'] ?? null,
                'parent_name' => $account['parent_name'] ?? null,
            ];

            $row = $existing->get($externalId);

            if ($row === null) {
                $created++;
                $inserts[] = $fields + [
                    'id' => (string) Str::uuid(),
                    'provider_connection_id' => $connection->getKey(),
                    'external_id' => $externalId,
                    'account_type' => 'ad_account',
                    'discovered_at' => $now,
                    'access_lost_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                continue;
            }

            if ($row->parent_name === null && ($account['parent_name'] ?? null) !== null) {
                // Counted so the interface can say «12 organisation names filled in» rather than
                // «refreshed», which tells somebody staring at UUIDs nothing about whether it worked.
                $named++;
            }

            $row->fill($fields);

            if ($row->isDirty()) {
                // Reachable again. An account that came back is no longer lost, whatever it was
                // last time — this is the only place that clears the mark.
                $row->forceFill(['discovered_at' => $now, 'access_lost_at' => null])->save();
            } else {
                $unchanged[] = (string) $row->getKey();
            }
        }

        foreach (array_chunk($inserts, 200) as $chunk) {
            ExternalAccount::withoutGlobalScopes()->insert($chunk);
        }

        foreach (array_chunk($unchanged, 500) as $chunk) {
            ExternalAccount::withoutGlobalScopes()->whereIn('id', $chunk)
                ->update(['discovered_at' => $now, 'access_lost_at' => null, 'updated_at' => $now]);
        }

        /*
         * GADS-DISCOVERY-PARTIAL-001 — the customers this read could not open. Their accounts were not
         * SEEN, but that is not evidence they were lost: with any customer refused, nothing is marked
         * unreachable, and the refusals travel to the interface as warnings.
         */
        $warnings = method_exists($connector, 'lastDiscoveryWarnings') ? $connector->lastDiscoveryWarnings() : [];
        $accessLost = $warnings === [] ? $this->markUnreachable($connection, $seen) : 0;

        $connection->forceFill([
            'last_health_check_at' => Carbon::now(),
            /*
             * Cleared on success, which is what lets a connection recover on its own.
             *
             * When a provider grants the access it was withholding, the next discovery answers and the
             * blocked state has to disappear without anybody clicking anything — otherwise the product
             * keeps explaining a problem that has been over for a week.
             */
            'last_discovery_succeeded_at' => Carbon::now(),
            'discovery_blocked_reason' => null,
        ])->save();

        return [
            'discovered' => count($accounts),
            'created' => $created,
            'named' => $named,
            'access_lost' => $accessLost,
            'inaccessible' => count($warnings),
            'warnings' => $warnings,
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
