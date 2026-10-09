<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Support\PlatformHttp;
use Illuminate\Support\Carbon;

/**
 * GA4-INTEGRATION-001 — what this Google identity can SEE, which is not what it will report on.
 *
 * The Admin API's `accountSummaries` returns, in one call, every Analytics account the authorised
 * identity can reach and the GA4 properties under each. That is a DISCOVERY, and the distinction
 * this class exists to keep is that discovery selects nothing:
 *
 *   «DISCOVERED ≠ SELECTED. Do not sync every property the Google identity can see. Sync only the
 *    explicitly selected/bound property.»
 *
 * An agency's Google account commonly reaches dozens of clients' properties. Syncing what it can
 * see would pull one client's web analytics into another client's project — the same failure
 * `ACCOUNT-SCOPE-ISOLATION-001` closes for ad accounts, which is why this reuses that machinery
 * rather than inventing a parallel one.
 *
 * ## Why properties are `ExternalAccount` rows
 *
 * GA4's hierarchy is Account → Property, which is the shape `ExternalAccount` already carries as
 * `parent_external_id` → `external_id`. Filing properties there means selection, binding, tenant
 * scope, «discovered but not selected», access-lost marking and the Connection Hub all work
 * unchanged. `account_type` keeps them apart from ad accounts, so nothing that asks for an ad
 * account can be handed a property by accident.
 */
final class Ga4PropertyDiscovery
{
    /** The type that keeps a measurement property out of every ad-account query. */
    public const ACCOUNT_TYPE = 'ga4_property';

    private const ADMIN_API = 'https://analyticsadmin.googleapis.com/v1beta';

    /** One page is 200 summaries; an identity reaching more than that is real and is paged. */
    private const PAGE_SIZE = 200;

    private const MAX_PAGES = 25;

    public function __construct(private readonly TokenVault $vault) {}

    /**
     * Discover and record every property this connection's identity can reach.
     *
     * Returns what was found, for the interface to present for SELECTION. Nothing here binds a
     * property to a project, and nothing here marks one for sync.
     *
     * @return array{properties: list<array<string, mixed>>, discovered: int}
     */
    public function discover(ProviderConnection $connection): array
    {
        $tokens = $this->vault->fresh($connection);

        $properties = [];
        $pageToken = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = PlatformHttp::client('ga4')
                ->withToken($tokens->accessToken)
                ->get(self::ADMIN_API.'/accountSummaries', array_filter([
                    'pageSize' => self::PAGE_SIZE,
                    'pageToken' => $pageToken,
                ]));

            if ($response->failed()) {
                /*
                 * The refusal is returned, never swallowed into an empty list.
                 *
                 * «No properties» and «Google refused» are different answers, and a customer shown
                 * the first when the second happened will go looking for a problem in their
                 * Analytics account that does not exist.
                 */
                throw new Ga4DiscoveryFailed(PlatformHttp::reason($response));
            }

            foreach ($response->json('accountSummaries') ?? [] as $summary) {
                $accountName = (string) ($summary['displayName'] ?? '');
                /* `accounts/123` → `123`; the bare id is what a human reads and what we key on. */
                $accountId = $this->idOf((string) ($summary['account'] ?? ''));

                foreach ($summary['propertySummaries'] ?? [] as $property) {
                    $properties[] = [
                        'property_id' => $this->idOf((string) ($property['property'] ?? '')),
                        'display_name' => (string) ($property['displayName'] ?? ''),
                        'account_id' => $accountId,
                        'account_name' => $accountName,
                        /*
                         * Present only on some summaries. Absent is recorded as absent — a property
                         * whose type Google did not state is not thereby a web property.
                         */
                        'property_type' => $property['propertyType'] ?? null,
                    ];
                }
            }

            $pageToken = $response->json('nextPageToken');

            if (! is_string($pageToken) || $pageToken === '') {
                break;
            }
        }

        $this->record($connection, $properties);

        return ['properties' => $properties, 'discovered' => count($properties)];
    }

    /**
     * Write them down as discovered — and ONLY as discovered.
     *
     * No binding is created and no `is_active` is touched. A property becomes this project's only
     * when somebody selects it, which is a separate, explicit action.
     *
     * @param  list<array<string, mixed>>  $properties
     */
    private function record(ProviderConnection $connection, array $properties): void
    {
        foreach ($properties as $property) {
            ExternalAccount::withoutGlobalScopes()->updateOrCreate(
                [
                    'provider_connection_id' => $connection->getKey(),
                    'external_id' => $property['property_id'],
                    'account_type' => self::ACCOUNT_TYPE,
                ],
                [
                    'tenant_id' => $connection->tenant_id,
                    'client_workspace_id' => $connection->client_workspace_id,
                    'provider' => 'ga4',
                    'name' => $property['display_name'],
                    'parent_external_id' => $property['account_id'],
                    'parent_name' => $property['account_name'],
                    'status' => 'active',
                    'discovered_at' => Carbon::now(),
                    /* Reachable again — the only place this mark is cleared, as for ad accounts. */
                    'access_lost_at' => null,
                ],
            );
        }
    }

    /** `properties/123456` and `accounts/789` both carry their id after the slash. */
    private function idOf(string $resourceName): string
    {
        $parts = explode('/', $resourceName);

        return end($parts) ?: '';
    }
}
