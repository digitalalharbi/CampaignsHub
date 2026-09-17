<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\ReportsPeriodReach;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Models\PeriodReach;
use Illuminate\Support\Carbon;

/**
 * REACH-PERIOD-001 — ask the provider for reach over one exact window, and store what it said.
 *
 * Nothing here computes reach. The figure is the provider's answer for the window, at a grain the
 * connector states it deduplicates, and it is stored against that window alone. A provider that
 * cannot deduplicate a range, or a window longer than it documents, is not asked: its period reach is
 * «—», which is the truth rather than a gap to be filled.
 */
final class PeriodReachFetcher
{
    public function __construct(private readonly AdvertisingConnectorRegistry $registry) {}

    /** Whether this account's provider deduplicates reach over this window at all. */
    public function supports(ExternalAccount $account, Carbon $from, Carbon $to): bool
    {
        $connector = $this->registry->get($account->provider);

        if (! $connector instanceof ReportsPeriodReach || $connector->periodReachGrains() === []) {
            return false;
        }

        // Never ask a platform we hold no credentials for — the same rule the metrics sync keeps.
        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return false;
        }

        return $connector->periodReachWindowSupported($from->toDateString(), $to->toDateString());
    }

    /**
     * Fetch and store every supported grain for the window. Returns how many rows were stored.
     *
     * Provider errors propagate: a failed request stores nothing, so the window is asked again rather
     * than being remembered as «not reported».
     */
    public function fetch(ExternalAccount $account, Carbon $from, Carbon $to): int
    {
        if (! $this->supports($account, $from, $to)) {
            return 0;
        }

        $connector = $this->registry->get($account->provider);

        if (! $connector instanceof ReportsPeriodReach) {
            return 0;
        }

        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                return 0;
            }

            $connector = $connector->withConnection($connection);
        }

        $stored = 0;

        foreach ($connector->periodReachGrains() as $grain) {
            $rows = $connector->periodReach((string) $account->external_id, $grain, $from->toDateString(), $to->toDateString());

            if ($grain === PeriodReach::ACCOUNT && $rows === []) {
                // Asked, and nothing came back: remembered, so a card does not re-ask on every open.
                $rows = [['external_id' => (string) $account->external_id, 'reach' => null, 'impressions' => null, 'frequency' => null]];
            }

            foreach ($rows as $row) {
                $this->store($account, $grain, $row, $from, $to);
                $stored++;
            }
        }

        return $stored;
    }

    /** @param array{external_id: string, reach: float|null, impressions: float|null, frequency: float|null} $row */
    private function store(ExternalAccount $account, string $grain, array $row, Carbon $from, Carbon $to): void
    {
        $campaignId = $grain === PeriodReach::CAMPAIGN
            ? ExternalCampaign::withoutGlobalScopes()
                ->where('external_account_id', $account->getKey())
                ->where('external_id', $row['external_id'])
                ->value('id')
            : null;

        PeriodReach::withoutGlobalScopes()->updateOrCreate(
            [
                'external_account_id' => $account->getKey(),
                'grain' => $grain,
                'external_entity_id' => $row['external_id'],
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            [
                'tenant_id' => $account->tenant_id,
                'provider' => $account->provider,
                'external_campaign_id' => $campaignId,
                'reach' => $row['reach'],
                'frequency' => $row['frequency'],
                'impressions' => $row['impressions'],
                'state' => $row['reach'] === null ? PeriodReach::NOT_REPORTED : PeriodReach::REPORTED,
                'fetched_at' => Carbon::now(),
            ],
        );
    }
}
