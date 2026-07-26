<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Notifications\Models\AppNotification;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alerts/notifications for ONE campaign (CMC-12). Reads the shared app_notifications store filtered
 * to this campaign's entity, so counts and updates stay consistent with the global notification
 * center (no duplicate records). Project + tenant scoped via a fail-closed campaign lookup.
 */
final class CampaignAlertsController extends Controller
{
    public function index(Request $request, string $project, string $campaign): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        $model = UnifiedCampaign::query()->findOrFail($campaign); // 404 cross-project / unknown

        $query = AppNotification::query()
            ->where('entity_type', UnifiedCampaign::class)
            ->where('entity_id', (string) $model->id)
            ->latest('created_at');

        if ($status = $request->string('status')->toString()) {
            if (in_array($status, ['unread', 'read', 'snoozed', 'resolved'], true)) {
                $query->where('status', $status);
            }
        }

        $rows = $query->limit(100)->get()->map(fn (AppNotification $n) => [
            'id' => $n->id,
            'type' => $n->type,
            'severity' => $n->severity,
            'title' => $n->title,
            'message' => $n->message,
            'source' => $n->source,
            'status' => $n->status,
            'action_url' => $n->action_url,
            'created_at' => $n->created_at?->toIso8601String(),
        ])->all();

        $counts = AppNotification::query()
            ->where('entity_type', UnifiedCampaign::class)->where('entity_id', (string) $model->id)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return ApiResponse::success($rows, 'Campaign alerts.', meta: [
            'counts' => [
                'active' => (int) ($counts['unread'] ?? 0) + (int) ($counts['read'] ?? 0),
                'resolved' => (int) ($counts['resolved'] ?? 0),
                'snoozed' => (int) ($counts['snoozed'] ?? 0),
                'all' => (int) $counts->sum(),
            ],
        ]);
    }
}
