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
                ->selectRaw('SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(conversions) conversions, SUM(revenue) revenue')
                ->groupBy('creative_id')
                ->get()
                ->keyBy('creative_id');

        // Median CTR across creatives WITH impressions — the honest baseline for top/needs-attention.
        $ctrs = $metrics->map(fn ($m) => (float) $m->impressions > 0 ? (float) $m->clicks / (float) $m->impressions : null)
            ->filter(fn ($v) => $v !== null)->sort()->values();
        $medianCtr = $ctrs->isEmpty() ? null : (float) $ctrs[(int) floor(($ctrs->count() - 1) / 2)];

        $rows = $creatives->map(function (ExternalCreative $c) use ($campaignNames, $metrics, $medianCtr): array {
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
                'metrics' => $this->metricsFor($m),
                // Performance classification vs the workspace's own median CTR — explainable, never fabricated.
                'performance' => $this->classify($m, $medianCtr),
            ];
        })->values();

        return ApiResponse::success($rows, 'Creative library.');
    }

    /** @return array{spend: float, impressions: float, clicks: float, conversions: float, revenue: float, ctr: float|null, roas: float|null} */
    private function metricsFor(?object $m): array
    {
        $spend = (float) ($m->spend ?? 0);
        $impr = (float) ($m->impressions ?? 0);
        $clicks = (float) ($m->clicks ?? 0);
        $conv = (float) ($m->conversions ?? 0);
        $rev = (float) ($m->revenue ?? 0);

        return [
            'spend' => $spend,
            'impressions' => $impr,
            'clicks' => $clicks,
            'conversions' => $conv,
            'revenue' => $rev,
            'ctr' => $impr > 0 ? round($clicks / $impr, 4) : null,
            'roas' => $spend > 0 ? round($rev / $spend, 2) : null,
        ];
    }

    /**
     * Classify a creative against the workspace's own 30-day baseline:
     *   top             — CTR ≥ 1.5× the median CTR (with real impressions), or ROAS ≥ 2
     *   needs_attention — meaningful spend with zero conversions, or CTR ≤ 0.5× the median
     *   normal          — everything else with data;  null — no data in the window (unranked, honest)
     *
     * @return array{class: string, reason_ar: string, reason_en: string}|null
     */
    private function classify(?object $m, ?float $medianCtr): ?array
    {
        $spend = (float) ($m->spend ?? 0);
        $impr = (float) ($m->impressions ?? 0);
        if ($m === null || ($spend <= 0 && $impr <= 0)) {
            return null;
        }

        $clicks = (float) ($m->clicks ?? 0);
        $conv = (float) ($m->conversions ?? 0);
        $rev = (float) ($m->revenue ?? 0);
        $ctr = $impr > 0 ? $clicks / $impr : null;
        $roas = $spend > 0 ? $rev / $spend : null;

        if ($roas !== null && $roas >= 2) {
            return ['class' => 'top', 'reason_ar' => 'عائد إنفاق ' . round($roas, 1) . 'x', 'reason_en' => 'ROAS ' . round($roas, 1) . 'x'];
        }
        if ($ctr !== null && $medianCtr !== null && $medianCtr > 0 && $impr >= 1000 && $ctr >= $medianCtr * 1.5) {
            return ['class' => 'top', 'reason_ar' => 'نسبة نقر أعلى من الوسيط بـ' . round($ctr / $medianCtr, 1) . 'x', 'reason_en' => 'CTR ' . round($ctr / $medianCtr, 1) . 'x the median'];
        }
        if ($spend >= 500 && $conv <= 0) {
            return ['class' => 'needs_attention', 'reason_ar' => 'إنفاق بلا تحويلات', 'reason_en' => 'Spend with no conversions'];
        }
        if ($ctr !== null && $medianCtr !== null && $medianCtr > 0 && $impr >= 1000 && $ctr <= $medianCtr * 0.5) {
            return ['class' => 'needs_attention', 'reason_ar' => 'نسبة نقر منخفضة (≤ نصف الوسيط)', 'reason_en' => 'Low CTR (≤ half the median)'];
        }

        return ['class' => 'normal', 'reason_ar' => 'ضمن النطاق المعتاد', 'reason_en' => 'Within the usual range'];
    }
}
