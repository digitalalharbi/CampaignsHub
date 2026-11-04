<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Reports\Support\ReportReadMemo;

/**
 * LEAD-DESTINATION-001 — the destination of each unified campaign, from its ad sets.
 *
 * One destination when every ad set that states one agrees, `Mixed` when they differ, absent when no
 * ad set has said (a provider that does not publish one, or a structure not yet swept). Absent is not
 * `LandingPage`: nothing here fills a gap with the commonest answer.
 */
final class CampaignDestinations
{
    /**
     * @param  list<string>  $unifiedCampaignIds
     * @return array<string, LeadDestination>
     */
    public function for(array $unifiedCampaignIds): array
    {
        if ($unifiedCampaignIds === []) {
            return [];
        }

        $ids = array_values(array_unique($unifiedCampaignIds));
        $sorted = $ids;
        sort($sorted);

        // PERF-RECOVERY-001 — the campaign table and the configured result of one report build ask for the same destinations.
        return ReportReadMemo::remember('campaign-destinations|'.md5(implode(',', $sorted)), fn (): array => $this->read($ids));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, LeadDestination>
     */
    private function read(array $ids): array
    {
        $seen = [];

        ExternalAdSet::withoutGlobalScopes()
            ->whereIn('unified_campaign_id', $ids)
            ->whereNotNull('destination_type')
            ->get(['unified_campaign_id', 'destination_type'])
            ->each(function (ExternalAdSet $set) use (&$seen): void {
                $destination = LeadDestination::fromMeta((string) $set->destination_type);

                if ($destination !== null) {
                    $seen[(string) $set->unified_campaign_id][$destination->value] = $destination;
                }
            });

        $out = [];
        foreach ($seen as $id => $destinations) {
            $out[$id] = count($destinations) === 1 ? reset($destinations) : LeadDestination::Mixed;
        }

        return $out;
    }
}
