<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Adapters;

use App\Domains\Integrations\Contracts\AdvertisingConnector;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountDiscovery;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\ResourceSyncOutcome;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use Illuminate\Support\Carbon;

/** Any ad platform: `AccountDiscovery` and `AccountMetricsSyncer`, reached through the contract. */
final class AdvertisingAdapter extends ThinProviderAdapter
{
    public function __construct(string $provider, private readonly AdvertisingConnector $connector)
    {
        parent::__construct($provider);
    }

    public function label(): string
    {
        return $this->connector->label();
    }

    public function status(): ConnectorStatus
    {
        return $this->connector->status();
    }

    public function healthCheck(): HealthResult
    {
        return $this->connector->healthCheck();
    }

    protected function runDiscovery(ProviderConnection $connection): void
    {
        app(AccountDiscovery::class)->refresh($connection);
    }

    protected function runSync(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome
    {
        $run = app(AccountMetricsSyncer::class)->sync($resource, $from, $to);

        return new ResourceSyncOutcome((string) $run->getRawOriginal('status'), (int) $run->metrics_upserted, $run->error);
    }
}
