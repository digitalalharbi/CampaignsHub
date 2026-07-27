<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors;

use App\Domains\Integrations\Connectors\Contracts\Connector;
use App\Domains\Integrations\Connectors\Enums\Capability;
use App\Domains\Integrations\Connectors\Enums\ConnectionState;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Honest base adapter for real providers whose credentials / app approval are not yet provisioned.
 * It declares the capabilities the real integration WILL have (so the UI can plan the surface) but
 * refuses to fabricate any data: no auth URL, no token refresh, and syncs fail loudly as a no-op.
 *
 * Each provider is configured (label + capabilities) in config/connectors.php; a future real
 * implementation can subclass this and override the live methods once credentials exist.
 */
class NullConnector implements Connector
{
    /** @param list<string> $capabilities */
    public function __construct(
        protected readonly string $provider,
        protected readonly string $label,
        protected readonly array $capabilities = [],
    ) {}

    public function provider(): string
    {
        return $this->provider;
    }

    public function label(): string
    {
        return $this->label;
    }

    /** @return list<string> */
    public function capabilities(): array
    {
        return Capability::normalize($this->capabilities);
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability->value, $this->capabilities(), true);
    }

    public function hasCredentials(): bool
    {
        return false;
    }

    public function isSandbox(): bool
    {
        return false;
    }

    public function authUrl(?ProviderConnection $connection = null): ?string
    {
        // No OAuth app configured — cannot honestly begin a flow.
        return null;
    }

    public function exchangeCode(string $code, array $params = []): ConnectionState
    {
        return ConnectionState::AwaitingCredentials;
    }

    public function syncMetrics(?ProviderConnection $connection, SyncWindow $window): SyncResult
    {
        return SyncResult::failed(
            $this->label.' is awaiting an external dependency (real credentials); no data was synced.',
        );
    }

    public function refreshToken(ProviderConnection $connection): bool
    {
        return false;
    }
}
