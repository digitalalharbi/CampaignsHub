<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Adapters;

use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderDefinition;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\CapabilityNotDeclared;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\DiscoveredResources;
use App\Domains\Integrations\ValueObjects\ResourceSyncOutcome;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * INTEG-CAPABILITY-CONTRACT-001 — what is the same about every adapter: the declaration is asked first.
 *
 * Subclasses delegate to a family's existing service and add no logic of their own, so the behaviour
 * under them is exactly the behaviour those services' suites already pin.
 */
abstract class ThinProviderAdapter implements ProviderAdapter
{
    public function __construct(protected readonly string $provider) {}

    final public function key(): string
    {
        return $this->definition()->key;
    }

    final public function definition(): ProviderDefinition
    {
        return ProviderCatalogue::declaration($this->provider)
            ?? throw new InvalidArgumentException("Unknown integration provider '{$this->provider}'.");
    }

    final public function supports(ProviderCapability $capability): bool
    {
        return $this->definition()->supports($capability);
    }

    final public function discover(ProviderConnection $connection): DiscoveredResources
    {
        if (! $this->supports(ProviderCapability::Discovery)) {
            throw CapabilityNotDeclared::for($this->key(), ProviderCapability::Discovery);
        }

        $this->runDiscovery($connection);

        return new DiscoveredResources(
            ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->getKey())->get(),
        );
    }

    final public function syncResource(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome
    {
        $declared = $this->definition();

        // An adapter is one provider and one resource type; anything else is a wiring mistake, not a sync.
        if ($resource->provider !== $declared->key || $resource->account_type !== $declared->capabilities->accountType) {
            throw new InvalidArgumentException("A {$resource->provider} {$resource->account_type} is not a {$declared->key} resource.");
        }

        return $this->runSync($resource, $from, $to);
    }

    abstract protected function runDiscovery(ProviderConnection $connection): void;

    abstract protected function runSync(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome;
}
