<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * INTEG-ON-DEMAND-DISCOVERY-001 (Owner 2026-11-06) — what a project's link journey may choose from, asked of
 * the provider when the person reaches that step and never written to the database.
 *
 * Two questions, in the order the journey asks them: the organisations this sign-in reaches (one provider
 * call), then the ad accounts of THE CHOSEN organisation. Nothing else is walked, and no background job
 * comes back for the rest: of ~2450 accounts the old discovery stored for this product, 31 were ever linked.
 *
 * ## Held for the length of a journey, not stored
 *
 * The provider's answer is kept in the cache for {@see self::TTL_MINUTES} so that typing in the search box
 * and turning a page do not each cost a provider call. It is keyed by the connection — which belongs to one
 * project — and it is not a catalogue: it expires, it is dropped when the link is confirmed, and no reader
 * other than this journey ever sees it. `external_accounts` gains a row only when an account is confirmed.
 */
final class LinkDirectory
{
    public const TTL_MINUTES = 10;

    public const PER_PAGE = 25;

    private const PREFIX = 'integration-link-directory:';

    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** @return list<array{external_id:?string, name:?string}> */
    public function organisations(ProviderConnection $connection): array
    {
        /** @var list<array{external_id:?string, name:?string}> $organisations */
        $organisations = Cache::remember(
            $this->key($connection, 'organisations'),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => $this->connector($connection)->listOrganisations(),
        );

        return $organisations;
    }

    /**
     * One page of the chosen organisation's accounts, filtered by name or id on the server.
     *
     * @return array{items: list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function accounts(ProviderConnection $connection, ?string $organisationId, ?string $search, int $page): array
    {
        $all = $this->accountsOf($connection, $organisationId);
        $needle = mb_strtolower(trim((string) $search));

        if ($needle !== '') {
            $all = array_values(array_filter($all, static fn (array $a): bool => str_contains(mb_strtolower((string) $a['name']), $needle)
                || str_contains(mb_strtolower((string) $a['external_id']), $needle)));
        }

        $page = max(1, $page);

        return [
            'items' => array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'total' => count($all),
            'page' => $page,
            'per_page' => self::PER_PAGE,
        ];
    }

    /**
     * The one account the person chose, as the provider described it — or null when the provider did not
     * list it under that organisation, which is the only basis on which anything is stored.
     *
     * @return array<string,mixed>|null
     */
    public function find(ProviderConnection $connection, ?string $organisationId, string $externalId): ?array
    {
        foreach ($this->accountsOf($connection, $organisationId) as $account) {
            if ((string) $account['external_id'] === $externalId) {
                return $account;
            }
        }

        return null;
    }

    public function forget(ProviderConnection $connection): void
    {
        foreach ($this->organisations($connection) as $organisation) {
            Cache::forget($this->key($connection, 'accounts:'.($organisation['external_id'] ?? '')));
        }
        Cache::forget($this->key($connection, 'organisations'));
    }

    /** @return list<array<string,mixed>> */
    private function accountsOf(ProviderConnection $connection, ?string $organisationId): array
    {
        $organisation = null;
        foreach ($this->organisations($connection) as $candidate) {
            if ($candidate['external_id'] === $organisationId) {
                $organisation = $candidate;
            }
        }

        // An organisation the provider did not list for this sign-in is never asked about.
        if ($organisation === null) {
            return [];
        }

        /** @var list<array<string,mixed>> $accounts */
        $accounts = Cache::remember(
            $this->key($connection, 'accounts:'.($organisationId ?? '')),
            now()->addMinutes(self::TTL_MINUTES),
            function () use ($connection, $organisation): array {
                $rows = [];
                foreach ($this->connector($connection)->listAdAccountsOf($organisation['external_id']) as $a) {
                    // The provider's raw body is not kept, even for ten minutes.
                    $rows[] = [
                        'external_id' => (string) $a['external_id'],
                        'name' => (string) ($a['name'] ?? $a['external_id']),
                        'currency' => $a['currency'] ?? null,
                        'timezone' => $a['timezone'] ?? null,
                        'status' => (string) ($a['status'] ?? 'active'),
                        'parent_external_id' => $organisation['external_id'],
                        'parent_name' => $a['parent_name'] ?? $organisation['name'],
                    ];
                }
                usort($rows, static fn (array $x, array $y): int => [mb_strtolower($x['name']), $x['external_id']] <=> [mb_strtolower($y['name']), $y['external_id']]);

                return $rows;
            },
        );

        return $accounts;
    }

    private function connector(ProviderConnection $connection): ApiAdvertisingConnector
    {
        $connector = $this->registry->forDiscovery((string) $connection->provider);

        if ($connector === null) {
            throw new RuntimeException('This platform has no account listing.');
        }

        return $connector->withConnection($connection);
    }

    private function key(ProviderConnection $connection, string $what): string
    {
        return self::PREFIX.$connection->getKey().':'.$what;
    }
}
