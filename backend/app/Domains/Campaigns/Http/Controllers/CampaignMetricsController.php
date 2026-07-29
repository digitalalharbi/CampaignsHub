<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-campaign metrics for the Campaign Command Center. Everything is scoped to ONE unified campaign
 * inside the active project (tenant + project global scopes + a fail-closed campaign lookup), so a
 * cross-project or unknown campaign id returns 404 — never another campaign's numbers.
 *
 * Reuses {@see MetricsAggregator} via forCampaign() so the KPI/derivation logic is identical to the
 * project-level analytics (ROAS/CPA/CTR/CPC/CPM computed from sums, project-currency normalized).
 */
final class CampaignMetricsController extends Controller
{
    public function __construct(private readonly MetricsAggregator $agg) {}

    /** KPI totals + previous-period deltas for the campaign. */
    public function summary(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);

        $agg = $this->agg->forCampaign($id);
        $current = $agg->totals($from, $to);
        $previous = $agg->totals($prevFrom, $prevTo);

        $deltas = [];
        foreach ($current as $k => $v) {
            $p = $previous[$k] ?? null;
            $deltas[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        return ApiResponse::success(
            ['current' => $current, 'previous' => $previous, 'delta' => $deltas],
            'Campaign metrics summary.',
            meta: $this->meta($from, $to),
        );
    }

    /**
     * CAMPDET-010 — conversion events actually RECORDED for this campaign, not a catalogue of events the
     * platform could theoretically send. Only non-zero keys are returned, so the UI shows what really
     * happened instead of a wall of zeros, and the campaign's own declared conversion purpose is
     * returned alongside so a mismatch (e.g. "purpose = purchase" but zero purchases) is visible.
     */
    public function events(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        $totals = $this->agg->forCampaign($id)->totals($from, $to);

        // Event-shaped metrics only — spend/revenue/derived ratios are not events.
        $keys = [
            'purchases' => ['ar' => 'عمليات شراء', 'en' => 'Purchases'],
            'leads' => ['ar' => 'عملاء محتملون', 'en' => 'Leads'],
            'qualified_leads' => ['ar' => 'عملاء محتملون مؤهلون', 'en' => 'Qualified leads'],
            'registrations' => ['ar' => 'تسجيلات', 'en' => 'Registrations'],
            'installs' => ['ar' => 'تثبيتات', 'en' => 'Installs'],
            'in_app_events' => ['ar' => 'أحداث داخل التطبيق', 'en' => 'In-app events'],
            'landing_page_views' => ['ar' => 'مشاهدات صفحة الهبوط', 'en' => 'Landing page views'],
            'engagements' => ['ar' => 'تفاعلات', 'en' => 'Engagements'],
            'video_views' => ['ar' => 'مشاهدات الفيديو', 'en' => 'Video views'],
            'video_completions' => ['ar' => 'إكمال الفيديو', 'en' => 'Video completions'],
            'conversions' => ['ar' => 'تحويلات (إجمالي)', 'en' => 'Conversions (total)'],
        ];

        $spend = (float) ($totals['spend'] ?? 0);
        $events = [];
        foreach ($keys as $key => $label) {
            $count = (float) ($totals[$key] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $events[] = [
                'key' => $key,
                'label_ar' => $label['ar'],
                'label_en' => $label['en'],
                'count' => round($count, 2),
                // Cost per event is only meaningful when there was spend to divide.
                'cost_per' => $spend > 0 ? round($spend / $count, 2) : null,
            ];
        }

        $model = UnifiedCampaign::query()->find($id);

        return ApiResponse::success([
            'events' => $events,
            'spend' => round($spend, 2),
            'declared_purpose' => $model?->primary_conversion_purpose,
            'attribution_model' => $model?->attribution_model,
            'attribution_window' => $model?->attribution_window,
        ], 'Campaign events.', meta: $this->meta($from, $to));
    }

    /**
     * CAMPDET-010 — the real sync history behind this campaign's numbers: every metric sync run for the
     * external accounts that feed it. This is what makes "last synced" auditable instead of a claim;
     * failures are returned with their error text rather than hidden.
     */
    public function syncLog(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);

        // Accounts that actually feed this campaign — a campaign with no links has no sync history.
        $accountIds = ExternalCampaign::query()
            ->where('unified_campaign_id', $id)
            ->pluck('external_account_id')
            ->filter()
            ->unique()
            ->values();

        $runs = $accountIds->isEmpty()
            ? collect()
            : MetricSyncRun::query()
                ->whereIn('external_account_id', $accountIds)
                ->orderByDesc('started_at')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();

        return ApiResponse::success([
            'linked_accounts' => $accountIds->count(),
            'runs' => $runs->map(fn (MetricSyncRun $r) => [
                'id' => $r->id,
                'provider' => $r->provider,
                'status' => $r->status,
                'window_start' => $r->window_start?->toDateString(),
                'window_end' => $r->window_end?->toDateString(),
                'metrics_upserted' => $r->metrics_upserted,
                'attempts' => $r->attempts,
                'started_at' => optional($r->started_at)->toIso8601String(),
                'finished_at' => optional($r->finished_at)->toIso8601String(),
                'error' => $r->error,
            ])->all(),
        ], 'Campaign sync log.');
    }

    /** Daily time series (spend/revenue/results/…) for the campaign — powers the Performance tab. */
    public function performance(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->timeseries($from, $to), 'Campaign performance.', meta: $this->meta($from, $to));
    }

    /** Per-platform contribution within this campaign. */
    public function platforms(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->byProvider($from, $to), 'Campaign platforms.', meta: $this->meta($from, $to));
    }

    /** Budget pacing for the campaign's linked spend. */
    public function budget(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->budgetPacing($from, $to, Carbon::today()), 'Campaign budget.', meta: $this->meta($from, $to));
    }

    /** Conversion funnel for the campaign. */
    public function funnel(Request $request, string $project, string $campaign): JsonResponse
    {
        $id = $this->resolve($request, $campaign);
        [$from, $to] = $this->range($request);

        return ApiResponse::success($this->agg->forCampaign($id)->funnel($from, $to), 'Campaign funnel.', meta: $this->meta($from, $to));
    }

    /** Fail-closed campaign lookup: 403 without permission, 404 for cross-project/unknown ids. */
    private function resolve(Request $request, string $campaign): string
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);

        return (string) UnifiedCampaign::query()->findOrFail($campaign)->id;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $to->copy()->subDays(29);

        return [$from->startOfDay(), $to->startOfDay()];
    }

    /** @return array<string, string> */
    private function meta(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'currency' => 'SAR', 'data_source' => 'daily_metrics'];
    }
}
