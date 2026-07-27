<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Resources\NotificationResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Notification center. Users only ever see their OWN notifications within their tenant (double
 * scoping: tenant global scope + explicit user_id filter).
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::query()
            // The user's own notifications PLUS tenant-wide operational alerts (budget risk, sync failure,
            // token expiry, …) which are raised with no specific recipient and must reach the whole team.
            ->where(fn ($q) => $q->where('user_id', $request->user()->id)->orWhereNull('user_id'))
            ->where('type', '!=', 'suppressed') // hide delivery-ledger tombstones
            ->latest('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($project = $request->string('project_id')->toString()) {
            $query->where('project_id', $project);
        }

        $unread = (clone $query)->where('status', 'unread')->count();

        return ApiResponse::success(
            NotificationResource::collection($query->limit(100)->get()),
            'Notifications retrieved.',
            meta: ['unread' => $unread],
        );
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(new NotificationResource($notification), 'Notification marked read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'unread')
            ->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked read.');
    }

    /**
     * Per-channel delivery log for the current user's notifications — the transparency surface for
     * queued / awaiting_credentials / sent / failed / retrying / suppressed states.
     */
    public function deliveries(Request $request): JsonResponse
    {
        $rows = DB::table('notification_deliveries')
            ->join('app_notifications as n', 'n.id', '=', 'notification_deliveries.notification_id')
            ->where('n.user_id', $request->user()->id)
            ->orderByDesc('notification_deliveries.created_at')
            ->limit(100)
            ->get([
                'notification_deliveries.id', 'notification_deliveries.channel', 'notification_deliveries.status',
                'notification_deliveries.attempts', 'notification_deliveries.created_at', 'n.type', 'n.title',
            ])
            ->map(fn ($r) => [
                'id' => $r->id,
                'channel' => $r->channel,
                'status' => $r->status,
                'attempts' => (int) $r->attempts,
                'type' => $r->type,
                'title' => $r->title,
                'created_at' => optional($r->created_at)->toIso8601String(),
            ]);

        return ApiResponse::success($rows, 'Delivery log retrieved.');
    }
}
