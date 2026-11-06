<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ProviderConnection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * INTEG-PROJECT-LINK-STORES-GA4-001 (Owner 2026-11-06) — what a project's link journey may choose from when
 * the source is a STORE or a GA4 PROPERTY: asked of the platform when the person reaches that step, and never
 * written to the database.
 *
 * The twin of {@see LinkDirectory} for the two source kinds that have no «organisation → ad account» shape:
 *
 *  - a store platform's authorisation reaches the one store that granted it;
 *  - a Google sign-in reaches the properties that identity can read, and only those.
 *
 * ## Held for the length of a journey, not stored
 *
 * The platform's answer is kept in the cache for {@see self::TTL_MINUTES} so that typing in the search box
 * does not cost a provider call each time. It is keyed by the connection — which belongs to one project — it
 * expires, it is dropped when the link is confirmed, and its raw body is not kept. `external_accounts` gains
 * a row only when a store or a property is confirmed.
 */
final class SourceLinkDirectory
{
    public const TTL_MINUTES = 10;

    public const PER_PAGE = 25;

    private const PREFIX = 'integration-source-directory:';

    public function __construct(
        private readonly CommerceConnectorRegistry $stores,
        private readonly Ga4PropertyDiscovery $properties,
    ) {}

    /**
     * The account type and binding purpose a provider's sources are stored and bound with, or null when this
     * journey does not serve it (an advertising platform has its own: {@see LinkDirectory}).
     *
     * @return array{kind:string, account_type:string, purpose:string}|null
     */
    public static function shapeOf(string $provider): ?array
    {
        if (! ProviderCatalogue::has($provider)) {
            return null;
        }

        return match (ProviderCatalogue::get($provider)->kind) {
            ProviderKind::Commerce => ['kind' => 'commerce', 'account_type' => 'store', 'purpose' => 'ecommerce'],
            ProviderKind::Measurement => ['kind' => 'measurement', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'purpose' => 'analytics'],
            default => null,
        };
    }

    /**
     * One page of what this sign-in reaches, filtered by name or id on the server.
     *
     * @return array{items: list<array<string,mixed>>, total:int, page:int, per_page:int}
     */
    public function page(ProviderConnection $connection, ?string $search, int $page): array
    {
        $all = $this->all($connection);
        $needle = mb_strtolower(trim((string) $search));

        if ($needle !== '') {
            $all = array_values(array_filter($all, static fn (array $s): bool => str_contains(mb_strtolower((string) $s['name']), $needle)
                || str_contains(mb_strtolower((string) $s['external_id']), $needle)
                || str_contains(mb_strtolower((string) ($s['parent_name'] ?? '')), $needle)));
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
     * The one source the person chose, as the platform described it — or null when the platform did not
     * list it for this sign-in, which is the only basis on which anything is stored.
     *
     * @return array<string,mixed>|null
     */
    public function find(ProviderConnection $connection, string $externalId): ?array
    {
        foreach ($this->all($connection) as $source) {
            if ((string) $source['external_id'] === $externalId) {
                return $source;
            }
        }

        return null;
    }

    public function forget(ProviderConnection $connection): void
    {
        Cache::forget(self::PREFIX.$connection->getKey());
    }

    /** @return list<array<string,mixed>> */
    private function all(ProviderConnection $connection): array
    {
        /** @var list<array<string,mixed>> $sources */
        $sources = Cache::remember(
            self::PREFIX.$connection->getKey(),
            now()->addMinutes(self::TTL_MINUTES),
            fn (): array => match (self::shapeOf((string) $connection->provider)['kind'] ?? null) {
                'commerce' => $this->storesOf($connection),
                'measurement' => $this->propertiesOf($connection),
                default => throw new RuntimeException('This platform has no source listing.'),
            },
        );

        return $sources;
    }

    /** @return list<array<string,mixed>> */
    private function storesOf(ProviderConnection $connection): array
    {
        $connector = $this->stores->forDiscovery((string) $connection->provider);
        if ($connector === null) {
            throw new RuntimeException('This platform has no store listing.');
        }

        $rows = [];
        foreach ($connector->withConnection($connection)->fetchStores() as $store) {
            $currency = strtoupper(trim((string) ($store['currency'] ?? '')));
            // The platform's raw body is not kept, even for ten minutes.
            $rows[] = [
                'external_id' => (string) $store['external_id'],
                'name' => (string) ($store['name'] ?? $store['external_id']),
                // A store that does not state its currency has none here: its money is withheld, never assumed.
                'currency' => $currency === '' ? null : $currency,
                'timezone' => $store['timezone'] ?? null,
                'status' => ($store['status'] ?? '') === '' ? 'active' : (string) $store['status'],
                'parent_external_id' => null,
                'parent_name' => null,
                'detail' => $store['domain'] ?? null,
                'metadata' => ['domain' => $store['domain'] ?? null, 'plan' => $store['plan'] ?? null],
            ];
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function propertiesOf(ProviderConnection $connection): array
    {
        $rows = [];
        foreach ($this->properties->list($connection) as $property) {
            if ((string) $property['property_id'] === '') {
                continue;
            }
            $rows[] = [
                'external_id' => (string) $property['property_id'],
                'name' => $property['display_name'] === '' ? (string) $property['property_id'] : (string) $property['display_name'],
                // A property states its currency and timezone on its first read, not in the listing.
                'currency' => null,
                'timezone' => null,
                'status' => 'active',
                'parent_external_id' => $property['account_id'] === '' ? null : (string) $property['account_id'],
                'parent_name' => $property['account_name'] === '' ? null : (string) $property['account_name'],
                'detail' => $property['account_name'] === '' ? null : (string) $property['account_name'],
            ];
        }

        usort($rows, static fn (array $x, array $y): int => [mb_strtolower((string) $x['parent_name']), mb_strtolower($x['name']), $x['external_id']]
            <=> [mb_strtolower((string) $y['parent_name']), mb_strtolower($y['name']), $y['external_id']]);

        return $rows;
    }
}
