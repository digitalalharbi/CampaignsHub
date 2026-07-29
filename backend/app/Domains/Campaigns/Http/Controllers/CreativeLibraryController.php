<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-wide creative library (المحتويات): every ad creative synced across the workspace's campaigns, with
 * its campaign/provider/format/status and a real 30-day spend + impressions aggregate. Source-attributed and
 * fail-closed; thumbnails pass through only when the platform provided them (never fabricated).
 */
final class CreativeLibraryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $creatives = ExternalCreative::query()->latest('last_synced_at')->limit(500)->get();
        $ids = $creatives->pluck('id')->all();
        $campaignIds = $creatives->pluck('campaign_id')->filter()->unique()->values()->all();

        $campaignNames = $campaignIds === []
            ? collect()
            : UnifiedCampaign::query()->whereIn('id', $campaignIds)->pluck('name', 'id');

        $to = Carbon::today();
        $from = $to->copy()->subDays(29);
        $metrics = $ids === []
            ? collect()
            : DB::table('creative_daily_metrics')
                ->whereIn('creative_id', $ids)
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->select('creative_id')
                ->selectRaw('SUM(spend) spend, SUM(impressions) impressions')
                ->groupBy('creative_id')
                ->get()
                ->keyBy('creative_id');

        $rows = $creatives->map(function (ExternalCreative $c) use ($campaignNames, $metrics): array {
            $m = $metrics->get($c->id);

            return [
                'id' => $c->id,
                'name' => $c->name,
                'client_display_name' => $c->client_display_name,
                'provider' => $c->provider,
                'format' => $c->format,
                'status' => $c->status,
                'thumbnail_url' => $c->thumbnail_url,
                'preview_url' => $c->preview_url,
                'destination_url' => $c->destination_url,
                'has_preview' => $c->thumbnail_url !== null || $c->preview_url !== null,
                'campaign_id' => $c->campaign_id,
                'campaign_name' => $c->campaign_id !== null ? ($campaignNames[$c->campaign_id] ?? null) : null,
                'project_id' => $c->project_id,
                'is_demo' => (bool) $c->is_demo,
                'last_synced_at' => optional($c->last_synced_at)->toIso8601String(),
                'metrics' => [
                    'spend' => (float) ($m->spend ?? 0),
                    'impressions' => (float) ($m->impressions ?? 0),
                ],
            ];
        })->values();

        return ApiResponse::success($rows, 'Creative library.');
    }
}
