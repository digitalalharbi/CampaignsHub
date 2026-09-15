<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;

/**
 * LAUNCH-SUCCESS-001 — what actually went live, said by the server.
 *
 * A launch celebration is only worth anything if it is describing something that happened. This is
 * the description: the server's own activation timestamp, and the linked platform campaigns with the
 * status each platform last reported for them. The browser is told the outcome; it never decides it.
 *
 * ## Why «partial» exists
 *
 * Activating in CampaignsHub sets this product's status. It does not reach into Meta or Google and
 * turn anything on — those campaigns carry their own status, imported by the structure sync. So a
 * campaign can be live here while a platform still has its half of it paused, pending review or
 * archived, and calling that a clean launch would be the product telling an operator their work is
 * running when it is not.
 *
 * Hence three honest shapes, never two:
 *
 *  - `launched` — active here, and every linked platform campaign is active too (or there are none
 *    linked, in which case nothing is claimed about any platform);
 *  - `partial`  — active here, but at least one linked platform campaign is not live. The ones that
 *    are not are named, with the status the platform reported, so the next move is obvious.
 *  - no outcome at all — the activation itself failed. Then there is no success response to carry
 *    this, and the UI has nothing to celebrate with.
 *
 * `live` is deliberately computed from the platform's own normalised status rather than assumed
 * from the link existing: a linked campaign is evidence of a connection, not of delivery.
 */
final class CampaignLaunchOutcome
{
    /** @return array<string, mixed> */
    public function for(UnifiedCampaign $campaign): array
    {
        /** @var list<ExternalCampaign> $linked */
        $linked = ExternalCampaign::query()
            ->where('unified_campaign_id', $campaign->id)
            ->orderBy('provider')
            ->get()
            ->all();

        $platforms = array_map(fn (ExternalCampaign $e): array => [
            'provider' => $e->provider,
            'external_id' => $e->external_id,
            'name' => $e->name,
            'status' => $e->status,
            'live' => $e->status === CampaignStatus::Active->value,
        ], $linked);

        $pending = array_values(array_filter($platforms, static fn (array $p): bool => $p['live'] === false));

        return [
            'campaign_id' => (string) $campaign->id,
            'project_id' => (string) $campaign->project_id,
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'total_budget' => $campaign->total_budget !== null ? (float) $campaign->total_budget : null,
            'budget_currency' => $campaign->budget_currency,
            // The server's clock, not the browser's — this is the evidence that a launch happened.
            'activated_at' => optional($campaign->activated_at)->toIso8601String(),
            'platforms' => $platforms,
            'platforms_live' => count($platforms) - count($pending),
            'platforms_total' => count($platforms),
            'outcome' => $pending === [] ? 'launched' : 'partial',
        ];
    }
}
