<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Adapters;

use App\Domains\Commerce\Contracts\CommerceConnector;
use App\Domains\Commerce\Services\StoreDiscovery;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\ResourceSyncOutcome;
use Illuminate\Support\Carbon;

/** Any store platform: `StoreDiscovery` and `StoreSyncer`, reached through the contract. */
final class CommerceAdapter extends ThinProviderAdapter
{
    public function __construct(string $provider, private readonly CommerceConnector $connector)
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
        app(StoreDiscovery::class)->discover($connection);
    }

    protected function runSync(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome
    {
        $run = app(StoreSyncer::class)->sync($resource, $from, $to);

        return new ResourceSyncOutcome((string) $run->getRawOriginal('status'), (int) $run->records, $run->error);
    }
}
