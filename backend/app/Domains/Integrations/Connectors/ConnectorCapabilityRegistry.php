<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors;

use App\Domains\Integrations\Connectors\Contracts\Connector;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves a {@see Connector} per provider from config/connectors.php. Each entry either names a
 * dedicated connector class (e.g. the Sandbox) or defaults to a config-driven {@see NullConnector}
 * carrying that provider's declared label + capabilities. Instances are built once and cached.
 *
 * This is additive to (and namespaced apart from) the legacy AdvertisingConnectorRegistry — it does
 * not replace it.
 */
final class ConnectorCapabilityRegistry
{
    /** @var array<string, Connector>|null */
    private ?array $connectors = null;

    /**
     * @param  array<string, array<string, mixed>>|null  $config  provider => definition (null → config)
     */
    public function __construct(private readonly ?array $config = null)
    {
        //
    }

    /** @return array<string, Connector> */
    public function all(): array
    {
        if ($this->connectors !== null) {
            return $this->connectors;
        }

        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = $this->config ?? config('connectors.connectors', []);

        $built = [];
        foreach ($definitions as $provider => $definition) {
            $built[$provider] = $this->make($provider, $definition);
        }

        return $this->connectors = $built;
    }

    public function get(string $provider): ?Connector
    {
        return $this->all()[$provider] ?? null;
    }

    public function has(string $provider): bool
    {
        return isset($this->all()[$provider]);
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->all());
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function make(string $provider, array $definition): Connector
    {
        /** @var class-string<Connector> $default */
        $default = config('connectors.default', NullConnector::class);

        /** @var class-string<Connector> $class */
        $class = $definition['connector'] ?? $default;

        // Null-backed connectors are configured (label + capabilities) from config; dedicated
        // connectors (e.g. the Sandbox) own their identity and are resolved from the container.
        if ($class === NullConnector::class || is_subclass_of($class, NullConnector::class)) {
            /** @var string $label */
            $label = $definition['label'] ?? Str::headline($provider);
            /** @var array<int,string> $capabilities */
            $capabilities = $definition['capabilities'] ?? [];

            return new $class($provider, $label, $capabilities);
        }

        $connector = app($class);
        if (! $connector instanceof Connector) {
            throw new InvalidArgumentException("Connector [{$class}] for [{$provider}] must implement the Connector contract.");
        }

        return $connector;
    }
}
