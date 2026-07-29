<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CAMPDET-010 — the ad-set and ad hierarchy beneath a unified campaign.
 *
 * The response distinguishes three genuinely different situations instead of collapsing them into one
 * empty state: the campaign is linked to no platform campaign at all; it is linked but the structure has
 * never been pulled; or the structure exists and is returned. Every row carries whether it came from an
 * API sync or from demo data, so a demo hierarchy is never mistaken for a live platform pull.
 */
final class CampaignStructureController extends Controller
{
    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $model = UnifiedCampaign::query()->findOrFail($campaign);

        $externalCampaigns = ExternalCampaign::query()
            ->where('unified_campaign_id', $model->id)
            ->get(['id', 'provider', 'external_id', 'name']);

        $adSets = $externalCampaigns->isEmpty()
            ? collect()
            : ExternalAdSet::query()
                ->whereIn('external_campaign_id', $externalCampaigns->pluck('id'))
                ->with(['ads' => fn ($q) => $q->orderBy('name')])
                ->orderBy('name')
                ->get();

        return ApiResponse::success([
            'linked_platform_campaigns' => $externalCampaigns->map(fn (ExternalCampaign $e) => [
                'id' => $e->id, 'provider' => $e->provider, 'external_id' => $e->external_id, 'name' => $e->name,
            ])->values()->all(),
            'ad_sets' => $adSets->map(fn (ExternalAdSet $s) => [
                'id' => $s->id,
                'provider' => $s->provider,
                'external_id' => $s->external_id,
                'name' => $s->name,
                'status' => $s->status,
                'optimization_goal' => $s->optimization_goal,
                'bid_strategy' => $s->bid_strategy,
                'daily_budget' => $s->daily_budget !== null ? (float) $s->daily_budget : null,
                'lifetime_budget' => $s->lifetime_budget !== null ? (float) $s->lifetime_budget : null,
                'currency' => $s->currency,
                'targeting' => $s->targeting,
                'is_demo' => (bool) $s->is_demo,
                'source_type' => $s->source_type,
                'last_synced_at' => optional($s->last_synced_at)->toIso8601String(),
                'ads' => $s->ads->map(fn ($a) => [
                    'id' => $a->id,
                    'external_id' => $a->external_id,
                    'name' => $a->name,
                    'status' => $a->status,
                    'review_status' => $a->review_status,
                    'destination_url' => $a->destination_url,
                    'is_demo' => (bool) $a->is_demo,
                ])->values()->all(),
            ])->values()->all(),
            // Why the list may be empty — stated, not left for the reader to guess.
            'state' => match (true) {
                $externalCampaigns->isEmpty() => 'not_linked',
                $adSets->isEmpty() => 'not_synced',
                default => 'ready',
            },
        ], 'Campaign structure.');
    }
}
