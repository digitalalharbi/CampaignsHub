<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Registry;

use App\Domains\Commerce\Registry\CommerceConnectorRegistry;
use App\Domains\Integrations\Adapters\AdvertisingAdapter;
use App\Domains\Integrations\Adapters\CommerceAdapter;
use App\Domains\Integrations\Adapters\Ga4Adapter;
use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\GoogleAdsConnector;
use App\Domains\Integrations\Providers\LinkedInConnector;
use App\Domains\Integrations\Providers\MetaConnector;
use App\Domains\Integrations\Providers\OpenAiAdsConnector;
use App\Domains\Integrations\Providers\ReportsPeriodReach;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Providers\XConnector;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;
use InvalidArgumentException;

/**
 * Registry of available advertising connectors. The Sandbox connector is only registered outside
 * production so fake data can never appear on a live deployment.
 */
final class AdvertisingConnectorRegistry
{
    /**
     * Additional keys a connector must answer to, because stored data and connector keys disagree.
     *
     * @var array<string, list<string>>
     */
    private const ALIASES = [
        'google_ads' => ['google'],
    ];

    /** @var array<string, AdvertisingConnector> */
    private array $connectors = [];

    public function __construct(bool $includeSandbox = true)
    {
        /** @var list<class-string<AdvertisingConnector>> $classes */
        $classes = [
            MetaConnector::class,
            TikTokConnector::class,
            SnapchatConnector::class,
            XConnector::class,
            LinkedInConnector::class,
            GoogleAdsConnector::class,
            OpenAiAdsConnector::class,
        ];

        if ($includeSandbox) {
            array_unshift($classes, SandboxAdvertisingConnector::class);
        }

        foreach ($classes as $class) {
            $connector = new $class;
            $this->connectors[$connector->key()] = $connector;

            // Provider keys drifted: the Google connector registers itself as "google_ads" while every
            // stored row (daily_metrics, external_accounts, external_campaigns) uses "google". Without
            // this alias a Google account resolves to NO connector, so its sync is recorded as
            // "no connector registered" instead of the truthful awaiting-credentials state.
            foreach (self::ALIASES[$connector->key()] ?? [] as $alias) {
                $this->connectors[$alias] = $connector;
            }
        }
    }

    /** @return array<string, AdvertisingConnector> */
    public function all(): array
    {
        return $this->connectors;
    }

    public function get(string $key): ?AdvertisingConnector
    {
        return $this->connectors[$key] ?? null;
    }

    /**
     * INTEG-CAPABILITY-CONTRACT-001 — add an ad platform's connector without editing the list above.
     *
     * The catalogue must already declare it as advertising: a connector nothing declares would be a
     * provider with no answer to any capability question.
     *
     * @throws InvalidArgumentException when no advertising declaration names this connector's key
     */
    public function register(AdvertisingConnector $connector): void
    {
        if (ProviderCatalogue::declaration($connector->key())?->kind !== ProviderKind::Advertising) {
            throw new InvalidArgumentException("No advertising provider declares '{$connector->key()}'.");
        }

        $this->connectors[$connector->key()] = $connector;
    }

    /**
     * The contract for ANY provider, of any family, or null when this build has none for it.
     *
     * `get()` stays advertising-only and `all()` stays connectors, because a caller of those wants
     * something it can ask for campaigns; GA4 and the stores must not appear there
     * (`Ga4IsNotAnAdPlatformTest`).
     */
    public function adapter(string $key): ?ProviderAdapter
    {
        $definition = ProviderCatalogue::declaration($key);

        if ($definition === null) {
            return null;
        }

        return match ($definition->kind) {
            ProviderKind::Advertising => ($connector = $this->connectors[$definition->key] ?? null) === null
                ? null
                : new AdvertisingAdapter($definition->key, $connector),
            ProviderKind::Commerce => ($store = app(CommerceConnectorRegistry::class)->get($definition->key)) === null
                ? null
                : new CommerceAdapter($definition->key, $store),
            ProviderKind::Measurement => $definition->key === 'ga4' ? new Ga4Adapter($definition->key) : null,
        };
    }

    /** @return array<string, ProviderAdapter> every declared provider this build has an adapter for */
    public function adapters(): array
    {
        $adapters = [];

        foreach (array_keys(ProviderCatalogue::declared()) as $key) {
            $adapter = $this->adapter($key);

            if ($adapter !== null) {
                $adapters[$key] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * Whether this provider declares the capability AND is present in this build.
     *
     * The second half is the sandbox in production: declared, and not registered.
     */
    public function supports(string $key, ProviderCapability $capability): bool
    {
        return $this->adapter($key)?->supports($capability) ?? false;
    }

    /** @return list<string> the keys that can do this, in the catalogue's order */
    public function supporting(ProviderCapability $capability): array
    {
        return array_keys(array_filter(
            $this->adapters(),
            static fn (ProviderAdapter $adapter): bool => $adapter->supports($capability),
        ));
    }

    /** The connector to run account discovery through, or null when the provider declares none. */
    public function forDiscovery(string $key): ?ApiAdvertisingConnector
    {
        $connector = $this->connectors[$key] ?? null;

        return $connector instanceof ApiAdvertisingConnector && ProviderCatalogue::supports($key, ProviderCapability::Discovery)
            ? $connector
            : null;
    }

    /**
     * The connector to ask for window reach, or null when the provider declares none.
     *
     * The `instanceof` is what gives the caller the type. `ProviderCapabilityContractTest` proves the
     * declaration and the interface agree for every provider, so it never decides the answer alone.
     */
    public function periodReach(string $key): (AdvertisingConnector&ReportsPeriodReach)|null
    {
        $connector = $this->connectors[$key] ?? null;

        return $connector instanceof ReportsPeriodReach && ProviderCatalogue::supports($key, ProviderCapability::PeriodReach)
            ? $connector
            : null;
    }
}
