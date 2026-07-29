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
    /** Campaign objective → ranking group. Groups are judged with DIFFERENT KPIs — never cross-compared. */
    private const OBJECTIVE_GROUP = [
        'sales' => 'conversion', 'conversions' => 'conversion', 'cart' => 'conversion',
        'leads' => 'lead', 'app_installs' => 'lead',
        'awareness' => 'awareness', 'reach' => 'awareness', 'video' => 'awareness',
        'traffic' => 'traffic', 'engagement' => 'traffic',
    ];

    /** group => [kpi name, higher-is-better]. conversion=ROAS↑, lead=CPA↓, awareness=CPM↓, traffic=CTR↑. */
    private const GROUP_KPI = [
        'conversion' => ['roas', true],
        'lead' => ['cpa', false],
        'awareness' => ['cpm', false],
        'traffic' => ['ctr', true],
    ];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        $creatives = ExternalCreative::query()->latest('last_synced_at')->limit(500)->get();
        $ids = $creatives->pluck('id')->all();
        $campaignIds = $creatives->pluck('campaign_id')->filter()->unique()->values()->all();

        $campaigns = $campaignIds === []
            ? collect()
            : UnifiedCampaign::query()->whereIn('id', $campaignIds)->get(['id', 'name', 'objective'])->keyBy('id');
        $campaignNames = $campaigns->map(fn ($c) => $c->name);

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

        // Objective-aware ranking: each creative is judged ONLY against creatives whose campaigns share its
        // objective GROUP, using that group's KPI — awareness content is never compared to sales content.
        $groupOf = fn (?string $objective): string => self::OBJECTIVE_GROUP[$objective] ?? 'traffic';
        $kpiValues = []; // group => list of the group's KPI values (for medians)
        foreach ($creatives as $c) {
            $m = $metrics->get($c->id);
            if ($m === null) {
                continue;
            }
            $group = $groupOf($c->campaign_id !== null ? ($campaigns[$c->campaign_id]->objective ?? null) : null);
            $v = $this->kpiValue($group, $m);
            if ($v !== null) {
                $kpiValues[$group][] = $v;
            }
        }
        $medians = [];
        foreach ($kpiValues as $group => $values) {
            sort($values);
            $medians[$group] = $values[(int) floor((count($values) - 1) / 2)];
        }

        $rows = $creatives->map(function (ExternalCreative $c) use ($campaigns, $campaignNames, $metrics, $medians, $groupOf): array {
            $m = $metrics->get($c->id);
            $objective = $c->campaign_id !== null ? ($campaigns[$c->campaign_id]->objective ?? null) : null;
            $group = $groupOf($objective);

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
                'objective' => $objective,
                'objective_group' => $group,
                'metrics' => $this->metricsFor($m),
                // The group's OWN KPI (name + value) — what this creative is actually judged on.
                'kpi' => ['name' => self::GROUP_KPI[$group][0], 'value' => $this->kpiValue($group, $m)],
                // Classification vs the creative's objective-group median — explainable, never cross-objective.
                'performance' => $this->classify($group, $m, $medians[$group] ?? null),
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
            'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
            'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
        ];
    }

    /** The group's KPI value for a creative's 30d sums (null when the inputs are missing). */
    private function kpiValue(string $group, ?object $m): ?float
    {
        if ($m === null) {
            return null;
        }
        $spend = (float) ($m->spend ?? 0);
        $impr = (float) ($m->impressions ?? 0);
        $clicks = (float) ($m->clicks ?? 0);
        $conv = (float) ($m->conversions ?? 0);
        $rev = (float) ($m->revenue ?? 0);

        return match ($group) {
            'conversion' => $spend > 0 ? round($rev / $spend, 2) : null,             // ROAS
            'lead' => $conv > 0 ? round($spend / $conv, 2) : null,                    // CPA
            'awareness' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,        // CPM
            default => $impr > 0 ? round($clicks / $impr, 4) : null,                  // CTR
        };
    }

    /** KPI display names per group (index 0 used in the payload). */
    private const KPI_LABEL = [
        'roas' => ['ar' => 'عائد الإنفاق', 'en' => 'ROAS'],
        'cpa' => ['ar' => 'تكلفة التحويل', 'en' => 'CPA'],
        'cpm' => ['ar' => 'تكلفة الألف ظهور', 'en' => 'CPM'],
        'ctr' => ['ar' => 'نسبة النقر', 'en' => 'CTR'],
    ];

    /**
     * Classify a creative vs its OWN objective-group median (top ≥1.5× better than the group median,
     * needs_attention ≥2× worse with real spend; lower-is-better KPIs invert). Reasons name the group KPI so
     * the judgement is explainable; null = no data in the window (unranked, honest).
     *
     * @return array{class: string, reason_ar: string, reason_en: string}|null
     */
    private function classify(string $group, ?object $m, ?float $median): ?array
    {
        $spend = (float) ($m->spend ?? 0);
        $impr = (float) ($m->impressions ?? 0);
        if ($m === null || ($spend <= 0 && $impr <= 0)) {
            return null;
        }

        [$kpiName, $higherBetter] = self::GROUP_KPI[$group];
        $label = self::KPI_LABEL[$kpiName];
        $v = $this->kpiValue($group, $m);

        // No KPI value (e.g. lead group with zero conversions): meaningful spend = attention, else unranked-normal.
        if ($v === null) {
            if ($group === 'lead' && $spend >= 500) {
                return ['class' => 'needs_attention', 'reason_ar' => 'إنفاق بلا تحويلات', 'reason_en' => 'Spend with no conversions'];
            }

            return ['class' => 'normal', 'reason_ar' => 'بيانات غير كافية للمقارنة', 'reason_en' => 'Not enough data to rank'];
        }
        if ($median === null || $median <= 0) {
            return ['class' => 'normal', 'reason_ar' => 'لا وسيط مجموعة للمقارنة', 'reason_en' => 'No group median to compare against'];
        }

        $ratio = $higherBetter ? $v / $median : $median / $v; // >1 = better than the group's median
        $fmt = $kpiName === 'ctr' ? round($v * 100, 2).'%' : round($v, 2).($kpiName === 'roas' ? 'x' : '');

        if ($ratio >= 1.5 && $impr >= 1000) {
            return ['class' => 'top', 'reason_ar' => $label['ar'].' '.$fmt.' (أفضل من وسيط مجموعته ×'.round($ratio, 1).')', 'reason_en' => $label['en'].' '.$fmt.' ('.round($ratio, 1).'× better than its group median)'];
        }
        if ($ratio <= 0.5 && $spend >= 500) {
            return ['class' => 'needs_attention', 'reason_ar' => $label['ar'].' '.$fmt.' (أسوأ من وسيط مجموعته)', 'reason_en' => $label['en'].' '.$fmt.' (worse than its group median)'];
        }

        return ['class' => 'normal', 'reason_ar' => 'ضمن نطاق مجموعته ('.$label['ar'].' '.$fmt.')', 'reason_en' => 'Within its group range ('.$label['en'].' '.$fmt.')'];
    }
}
