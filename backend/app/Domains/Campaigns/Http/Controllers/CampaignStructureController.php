<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CAMPDET-010 — the ad-set and ad hierarchy beneath a unified campaign.
 *
 * The response distinguishes genuinely different situations instead of collapsing them into one empty
 * panel: the campaign is linked to no platform campaign at all; the platform holds no credentials on
 * this install, so nothing could ever have been pulled; it is linked but the structure has never been
 * pulled; or the structure exists and is returned. Every row carries whether it came from an API sync
 * or from demo data, so a demo hierarchy is never mistaken for a live platform pull.
 *
 * STRUCT-001 added the credentials state — which used to be indistinguishable from «never synced», and
 * sent the reader to press a button that could not have worked — and the button that resolves the one
 * that IS resolvable.
 */
final class CampaignStructureController extends Controller
{
    public function __construct(private readonly ProviderConfigurationService $settings) {}

    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('campaigns.view'), 403);

        $model = UnifiedCampaign::query()->findOrFail($campaign);

        $externalCampaigns = ExternalCampaign::query()
            ->where('unified_campaign_id', $model->id)
            ->get(['id', 'provider', 'external_id', 'name', 'external_account_id']);

        $adSets = $externalCampaigns->isEmpty()
            ? collect()
            : ExternalAdSet::query()
                ->whereIn('external_campaign_id', $externalCampaigns->pluck('id'))
                ->with(['ads' => fn ($q) => $q->with('creative')->orderBy('name')])
                ->orderBy('name')
                ->get();

        /*
         * LinkedIn has no ad-set level, so its ads hang directly off the campaign (STRUCT-001).
         *
         * A reader that only walked the ad sets would show a LinkedIn campaign as empty while its ads
         * sat in the table — the exact bug the nullable column would otherwise have introduced.
         */
        $looseAds = $externalCampaigns->isEmpty()
            ? collect()
            : ExternalAd::query()
                ->whereIn('external_campaign_id', $externalCampaigns->pluck('id'))
                ->whereNull('external_ad_set_id')
                ->with('creative')
                ->orderBy('name')
                ->get();

        $providers = $externalCampaigns->pluck('provider')->unique();
        $awaiting = $providers->reject(fn (string $provider) => $this->settings->isConfigured($provider))->values();

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
                'ads' => $s->ads->map($this->adArray(...))->values()->all(),
            ])->values()->all(),
            'ads_without_ad_set' => $looseAds->map($this->adArray(...))->values()->all(),
            // Which of the linked platforms cannot be discovered at all here, named honestly.
            'awaiting_credentials' => $awaiting->all(),
            'state' => match (true) {
                $externalCampaigns->isEmpty() => 'not_linked',
                $adSets->isEmpty() && $looseAds->isEmpty() && $awaiting->count() === $providers->count() => 'awaiting_credentials',
                $adSets->isEmpty() && $looseAds->isEmpty() => 'not_synced',
                default => 'ready',
            },
        ], 'Campaign structure.');
    }

    /**
     * Ask for this campaign's structure now, rather than waiting for the six-hourly sweep.
     *
     * It QUEUES the same job the scheduler queues; it does not fetch inline. A platform call behind a
     * button is how a page hangs for thirty seconds and then times out with the work half done — and
     * the job is unique per account, so pressing twice costs nothing.
     */
    public function sync(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()->hasPermission('integrations.view'), 403);

        $model = UnifiedCampaign::query()->findOrFail($campaign);

        $accountIds = ExternalCampaign::query()
            ->where('unified_campaign_id', $model->id)
            ->pluck('external_account_id')
            ->unique();

        if ($accountIds->isEmpty()) {
            return ApiResponse::error(
                'This campaign is not linked to any platform campaign, so there is no structure to discover.',
                meta: ['queued' => 0],
                status: 422,
            );
        }

        // Only accounts behind a live connection. Queueing the rest writes a failure row saying
        // something the operator already knows from the integrations page.
        $connected = ProviderConnection::query()->where('status', 'connected')->pluck('id');

        $accounts = ExternalAccount::query()
            ->whereIn('id', $accountIds)
            ->whereIn('provider_connection_id', $connected)
            ->get(['id', 'provider']);

        if ($accounts->isEmpty()) {
            return ApiResponse::error(
                'None of the linked accounts has a connected authorisation. Reconnect the platform first.',
                meta: ['queued' => 0],
                status: 422,
            );
        }

        foreach ($accounts as $account) {
            SyncAccountStructureJob::dispatch((string) $account->id, ['source' => 'campaign_page']);
        }

        return ApiResponse::success(
            ['queued' => $accounts->count(), 'providers' => $accounts->pluck('provider')->unique()->values()->all()],
            'Structure discovery queued.',
            status: 202,
        );
    }

    /** @return array<string,mixed> */
    private function adArray(ExternalAd $ad): array
    {
        return [
            'id' => $ad->id,
            'external_id' => $ad->external_id,
            'name' => $ad->name,
            'status' => $ad->status,
            'review_status' => $ad->review_status,
            'destination_url' => $ad->destination_url,
            'is_demo' => (bool) $ad->is_demo,
            // Nullable throughout, and deliberately so: a platform that sends no thumbnail gets none,
            // and the panel says so rather than showing a link that would 404 in front of a client.
            'creative' => $ad->creative === null ? null : [
                'id' => $ad->creative->id,
                'name' => $ad->creative->name,
                'format' => $ad->creative->format,
                'thumbnail_url' => $ad->creative->thumbnail_url,
                'preview_url' => $ad->creative->preview_url,
            ],
        ];
    }
}
