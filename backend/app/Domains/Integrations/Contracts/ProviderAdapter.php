<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Exceptions\CapabilityNotDeclared;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\DiscoveredResources;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\ResourceSyncOutcome;
use Illuminate\Support\Carbon;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — the one contract every provider answers to (§1.8), whatever it is.
 *
 * `AdvertisingConnector` and `CommerceConnector` stay what they are: each is the set of questions its
 * own family can be asked, and bending one into the other is what `CommerceConnector`'s comment
 * refuses. GA4 has neither. This sits above all three as a thin door: identity, what the provider
 * declares, and the two operations every family shares, discovery and syncing one bound resource.
 *
 * Reached through `AdvertisingConnectorRegistry::adapter()`. The syncers are not routed through it:
 * they keep their own paths, and each implementation only delegates to one.
 *
 * Changing this interface means bumping `CONTRACT_VERSION`; §5 gate 5 allows the contract to grow only
 * as a versioned change, and `ProviderCapabilityContractTest` pins the surface to the number.
 */
interface ProviderAdapter
{
    public const CONTRACT_VERSION = 1;

    /** The catalogue's key: `google`, not the connector's `google_ads`. */
    public function key(): string;

    public function definition(): ProviderDefinition;

    public function supports(ProviderCapability $capability): bool;

    public function label(): string;

    public function status(): ConnectorStatus;

    public function healthCheck(): HealthResult;

    /**
     * Catalogue what this connection can reach. Selects nothing and binds nothing.
     *
     * @throws CapabilityNotDeclared when the provider declares no discovery, before anything is asked
     */
    public function discover(ProviderConnection $connection): DiscoveredResources;

    /** One bound resource, one window, through that family's own syncer and its own binding gate. */
    public function syncResource(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome;
}
