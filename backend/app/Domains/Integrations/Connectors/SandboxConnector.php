<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors;

use App\Domains\Integrations\Connectors\Contracts\Connector;
use App\Domains\Integrations\Connectors\Enums\Capability;
use App\Domains\Integrations\Connectors\Enums\ConnectionState;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Sandbox\SandboxAdvertisingConnector;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Fully-functional Connection Center adapter backed by the existing {@see SandboxAdvertisingConnector}.
 * It performs a REAL (deterministic, offline) sync of clearly-labelled demo data, so it is the one
 * connector that legitimately reaches {@see ConnectionState::SandboxVerified}. It never touches the
 * network and every record is tagged `sandbox` so it can never be mistaken for live platform data.
 */
final class SandboxConnector implements Connector
{
    public function __construct(
        private readonly SandboxAdvertisingConnector $inner = new SandboxAdvertisingConnector,
    ) {}

    public function provider(): string
    {
        return 'sandbox';
    }

    public function label(): string
    {
        return 'Sandbox (demo data)';
    }

    /** @return list<string> The Sandbox exercises the full adapter surface. */
    public function capabilities(): array
    {
        return Capability::values();
    }

    public function supports(Capability $capability): bool
    {
        return true;
    }

    public function hasCredentials(): bool
    {
        // The Sandbox's demo token is real and usable end-to-end — that is the whole point of it.
        return true;
    }

    public function isSandbox(): bool
    {
        return true;
    }

    public function authUrl(?ProviderConnection $connection = null): string
    {
        return $this->inner->authorizationUrl('connection-center');
    }

    public function exchangeCode(string $code, array $params = []): ConnectionState
    {
        return ConnectionState::SandboxVerified;
    }

    public function syncMetrics(?ProviderConnection $connection, SyncWindow $window): SyncResult
    {
        return $this->inner->syncInsights('sandbox-act-1', $window->from->toDateString(), $window->to->toDateString());
    }

    public function refreshToken(ProviderConnection $connection): bool
    {
        return true;
    }
}
