<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creatives for ONE campaign (CMC-8), ranked by the campaign's OBJECTIVE. Real, source-attributed
 * data aggregated from creative_daily_metrics; derived KPIs computed from sums (never fabricated).
 * Thumbnails are passed through only when the platform provided them. Each creative carries an
 * explainable ranking reason and a performance classification. Fail-closed, project/campaign scoped.
 */
final class CampaignCreativesController extends Controller
{
    public function __construct(private readonly CreativePresenter $presenter) {}

    /** Objective → the metric that ranks a creative (higher is better unless it's a cost). */
    private const RANK = [
        'sales' => ['roas', true], 'conversions' => ['roas', true],
        'awareness' => ['view_rate', true], 'reach' => ['view_rate', true],
        'traffic' => ['ctr', true], 'engagement' => ['ctr', true],
        'leads' => ['cpa', false], 'app_installs' => ['cpa', false],
        'video' => ['view_rate', true],
    ];

    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $model = UnifiedCampaign::query()->findOrFail($campaign); // 404 cross-project / unknown
        [$from, $to] = $this->range($request);

        // Aggregate each creative's metrics for the window (one pass, project-scoped).
        $agg = DB::table('creative_daily_metrics')
            ->where('campaign_id', $model->id)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->select('creative_id')
            ->selectRaw('SUM(spend) spend, SUM(impressions) impressions, SUM(clicks) clicks, SUM(conversions) conversions, SUM(revenue) revenue, SUM(video_views) video_views, SUM(video_completions) video_completions')
            ->groupBy('creative_id')
            ->get()
            ->keyBy('creative_id');

        $creatives = ExternalCreative::query()->where('campaign_id', $model->id)->get();

        [$rankKey, $higherBetter] = self::RANK[$model->objective] ?? ['roas', true];

        $rows = $creatives->map(function (ExternalCreative $c) use ($agg) {
            $m = $agg->get($c->id);
            $spend = (float) ($m->spend ?? 0);
            $impr = (float) ($m->impressions ?? 0);
            $clicks = (float) ($m->clicks ?? 0);
            $conv = (float) ($m->conversions ?? 0);
            $rev = (float) ($m->revenue ?? 0);
            $views = (float) ($m->video_views ?? 0);
            $completes = (float) ($m->video_completions ?? 0);

            return [
                'id' => $c->id, 'name' => $c->name, 'client_display_name' => $c->client_display_name,
                'provider' => $c->provider, 'format' => $c->format, 'status' => $c->status,
                /*
                 * AD-PREVIEW-001 — the canonical preview, not a second opinion about one.
                 *
                 * This endpoint decided for itself what «has a preview» meant:
                 * `thumbnail_url !== null || preview_url !== null`. That is wrong in both directions
                 * at once.
                 *
                 * Too generous: `preview_url` is the platform's shareable link, and
                 * `CreativePresenter` WITHHOLDS it when it carries a credential. So a creative whose
                 * only link is withheld reported `has_preview: true`, the card asked for a picture,
                 * and nothing arrived.
                 *
                 * Too mean: a creative with a real `asset_url` and no listing thumbnail — which is
                 * every Meta image ad since AD-MEDIA-RECOVERY-001 started reading `image_url` —
                 * reported `has_preview: false` and rendered «no preview» over an asset that was
                 * sitting in the row.
                 *
                 * `preview()` is the one place those rules live, and it answers with a STATE
                 * («available», «withheld», «expired», «unavailable») plus the reason, so the card
                 * can say which of the three silences it is looking at instead of showing the same
                 * grey box for all of them.
                 */
                'preview' => $this->presenter->preview($c),
                'is_demo' => $c->is_demo,
                'metrics' => [
                    'spend' => $spend, 'impressions' => $impr, 'clicks' => $clicks, 'conversions' => $conv, 'revenue' => $rev,
                    'roas' => $spend > 0 ? round($rev / $spend, 2) : null,
                    'cpa' => $conv > 0 ? round($spend / $conv, 2) : null,
                    'ctr' => $impr > 0 ? round($clicks / $impr, 4) : null,
                    'cpm' => $impr > 0 ? round($spend / $impr * 1000, 2) : null,
                    'view_rate' => $impr > 0 ? round($views / $impr, 4) : null,
                    'completion_rate' => $views > 0 ? round($completes / $views, 4) : null,
                ],
            ];
        })->values();

        // Campaign averages for the classification threshold + ranking reason.
        $avgRank = $rows->pluck("metrics.{$rankKey}")->filter(fn ($v) => $v !== null)->avg() ?? 0;

        $ranked = $rows->map(function (array $r) use ($rankKey, $higherBetter, $avgRank) {
            $val = $r['metrics'][$rankKey];
            $r['rank_metric'] = $rankKey;
            $r['rank_value'] = $val;
            $r['classification'] = $this->classify($r, $rankKey, $val, $higherBetter, $avgRank);
            $r['ranking_reason'] = $this->reason($rankKey, $val, $higherBetter, $avgRank);

            return $r;
        })->sortByDesc(fn ($r) => ($higherBetter ? 1 : -1) * ((float) ($r['rank_value'] ?? 0)))->values();

        return ApiResponse::success($ranked->all(), 'Campaign creatives.', meta: [
            'objective' => $model->objective, 'rank_metric' => $rankKey, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
        ]);
    }

    /** @param array<string,mixed> $r */
    private function classify(array $r, string $key, ?float $val, bool $higherBetter, float $avg): string
    {
        $conv = (float) $r['metrics']['conversions'];
        $impr = (float) $r['metrics']['impressions'];
        if ($impr < 100) {
            return 'insufficient_data';
        }
        if ($r['status'] !== 'active') {
            return 'paused';
        }
        if ($conv === 0.0 && in_array($key, ['roas', 'cpa'], true)) {
            return 'needs_improvement';
        }
        if ($val === null || $avg <= 0) {
            return 'promising';
        }
        $better = $higherBetter ? $val >= $avg * 1.15 : $val <= $avg * 0.85;
        $worse = $higherBetter ? $val < $avg * 0.7 : $val > $avg * 1.3;

        return $better ? 'top_performing' : ($worse ? 'needs_improvement' : 'promising');
    }

    private function reason(string $key, ?float $val, bool $higherBetter, float $avg): string
    {
        if ($val === null) {
            return 'لا تتوفر بيانات كافية لهذا المؤشر بعد.';
        }
        $cmp = $higherBetter ? ($val >= $avg ? 'أعلى من' : 'أقل من') : ($val <= $avg ? 'أفضل من' : 'أضعف من');

        return "تم التقييم على {$key}: {$val} ({$cmp} متوسط الحملة ".round($avg, 2).').';
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : Carbon::today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : $to->copy()->subDays(29);

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
