<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Campaigns\Models\ExternalAdSet;

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

        $seen = [];

        ExternalAdSet::withoutGlobalScopes()
            ->whereIn('unified_campaign_id', array_values(array_unique($unifiedCampaignIds)))
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
