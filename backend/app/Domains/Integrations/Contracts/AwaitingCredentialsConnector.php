<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Contracts;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;
use RuntimeException;

/**
 * Base for real platform connectors whose credentials/app approval are not yet provisioned.
 * It honestly reports {@see ConnectorStatus::AwaitingCredentials} and refuses to fabricate data —
 * sync/health fail loudly rather than pretending to succeed. Concrete connectors override key()/
 * label() and, once credentials exist, the OAuth + sync methods with real SDK calls.
 */
abstract class AwaitingCredentialsConnector implements AdvertisingConnector
{
    abstract public function key(): string;

    abstract public function label(): string;

    public function status(): ConnectorStatus
    {
        return ConnectorStatus::AwaitingCredentials;
    }

    public function authorizationUrl(string $state): string
    {
        throw new RuntimeException($this->label().' is awaiting credentials; OAuth is not configured.');
    }

    public function handleCallback(array $callback): ConnectorStatus
    {
        return ConnectorStatus::AwaitingCredentials;
    }

    public function healthCheck(): HealthResult
    {
        return HealthResult::down($this->label().' is awaiting credentials.');
    }

    public function listAdAccounts(): array
    {
        return [];
    }

    public function syncCampaigns(string $adAccountId): SyncResult
    {
        return SyncResult::failed($this->label().' is awaiting credentials.');
    }

    public function syncInsights(string $adAccountId, string $from, string $to): SyncResult
    {
        return SyncResult::failed($this->label().' is awaiting credentials.');
    }
}
