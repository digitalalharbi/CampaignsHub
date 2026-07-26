<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Reports\Models\Report;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports belonging to ONE campaign (CMC-13). Lists the same Report rows the Reports page uses,
 * filtered to this campaign, with their completed export tokens — so the tab reuses the real report
 * pipeline (no separate calculation). Project + tenant scoped; fail-closed campaign lookup.
 */
final class CampaignReportsController extends Controller
{
    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('reports.view'), 403);
        $model = UnifiedCampaign::query()->findOrFail($campaign); // 404 cross-project / unknown

        $reports = Report::query()
            ->where('campaign_id', (string) $model->id)
            ->with('exports')
            ->latest()
            ->get()
            ->map(fn (Report $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'type' => $r->type,
                'audience' => $r->audience,
                'status' => $r->status,
                'mode' => $r->mode,
                'is_demo' => $r->is_demo,
                'generated_at' => $r->generated_at?->toIso8601String(),
                'last_sent_at' => $r->last_sent_at?->toIso8601String(),
                'exports' => $r->exports->map(fn ($e) => [
                    'format' => $e->format, 'status' => $e->status, 'token' => $e->signed_token,
                ])->all(),
            ])->all();

        return ApiResponse::success($reports, 'Campaign reports.');
    }
}
