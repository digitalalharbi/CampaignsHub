<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Adapters;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Measurement\Ga4NotSelected;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4PropertySync;
use App\Domains\Integrations\Measurement\Ga4ReportFailed;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\ResourceSyncOutcome;
use App\Domains\Metrics\Enums\SyncRunStatus;
use Illuminate\Support\Carbon;

/**
 * GA4 has no connector interface, and this does not give it one: it is a measurement source, not an ad
 * platform (`Ga4IsNotAnAdPlatformTest`), so `AdvertisingConnectorRegistry::get('ga4')` stays null.
 *
 * `Ga4PropertySync` chooses its own dates in the property's timezone, so a window arrives here as a
 * number of days. Its two exceptions are the same outcomes the other families record on a run; its own
 * `IntegrationSyncRun` is written before they are thrown.
 */
final class Ga4Adapter extends ThinProviderAdapter
{
    public function label(): string
    {
        return $this->definition()->label;
    }

    public function status(): ConnectorStatus
    {
        return PlatformCredentials::for($this->provider)->isConfigured()
            ? ConnectorStatus::Disconnected
            : ConnectorStatus::AwaitingCredentials;
    }

    public function healthCheck(): HealthResult
    {
        return $this->status() === ConnectorStatus::AwaitingCredentials
            ? HealthResult::down($this->label().' is awaiting credentials.')
            : HealthResult::down($this->label().' is health-checked per connection, by discovery.');
    }

    protected function runDiscovery(ProviderConnection $connection): void
    {
        app(Ga4PropertyDiscovery::class)->discover($connection);
    }

    protected function runSync(ExternalAccount $resource, Carbon $from, Carbon $to): ResourceSyncOutcome
    {
        try {
            $result = app(Ga4PropertySync::class)->sync($resource, (int) $from->diffInDays($to) + 1);
        } catch (Ga4NotSelected $e) {
            return new ResourceSyncOutcome(SyncRunStatus::AwaitingAssignment->value, 0, $e->getMessage());
        } catch (Ga4ReportFailed $e) {
            return new ResourceSyncOutcome(SyncRunStatus::Failed->value, 0, $e->getMessage());
        }

        return new ResourceSyncOutcome(
            $result['figures'] > 0 ? SyncRunStatus::Success->value : SyncRunStatus::NoData->value,
            $result['figures'],
        );
    }
}
