<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Registry;

use App\Domains\Commerce\Contracts\CommerceConnector;
use App\Domains\Commerce\Providers\SallaConnector;
use App\Domains\Commerce\Providers\ZidConnector;

/**
 * COMMERCE-001 — the two store adapters, resolved by key.
 *
 * Separate from `AdvertisingConnectorRegistry` on purpose: a caller asking for a commerce connector
 * wants something that can be asked for orders, and one registry returning either kind would hand back
 * an object whose contract the caller has to test for before every call.
 *
 * The order is the brief's order — Salla, then Zid — and it is stated once here so no screen has to
 * pick its own.
 */
final class CommerceConnectorRegistry
{
    /** @var array<string, CommerceConnector> */
    private array $connectors = [];

    public function __construct()
    {
        foreach ([new SallaConnector, new ZidConnector] as $connector) {
            $this->connectors[$connector->key()] = $connector;
        }
    }

    public function get(?string $key): ?CommerceConnector
    {
        return $this->connectors[strtolower(trim((string) $key))] ?? null;
    }

    public function has(?string $key): bool
    {
        return $this->get($key) !== null;
    }

    /** @return array<string, CommerceConnector> */
    public function all(): array
    {
        return $this->connectors;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->connectors);
    }
}
