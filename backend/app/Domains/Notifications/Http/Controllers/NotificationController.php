<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Http\Controllers;

use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Notifications\Resources\NotificationResource;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification center. Users only ever see their OWN notifications within their tenant (double
 * scoping: tenant global scope + explicit user_id filter).
 */
final class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AppNotification::query()
            ->where('user_id', $request->user()->id)
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
        // Per-user notifications: only the owner. Project-wide alerts (null user): any tenant member.
        abort_unless($notification->user_id === null || $notification->user_id === $request->user()->id, 403);

        $notification->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(new NotificationResource($notification), 'Notification marked read.');
    }

    public function resolve(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.manage') || $notification->user_id === $request->user()->id, 403);
        $notification->update(['status' => 'resolved', 'resolved_at' => now()]);

        return ApiResponse::success(new NotificationResource($notification), 'Notification resolved.');
    }

    public function snooze(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($request->user()->hasPermission('alerts.manage') || $notification->user_id === $request->user()->id, 403);
        $until = $request->validate(['until' => ['required', 'date', 'after:now']])['until'];
        $notification->update(['status' => 'snoozed', 'snoozed_until' => $until]);

        return ApiResponse::success(new NotificationResource($notification), 'Notification snoozed.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'unread')
            ->update(['status' => 'read', 'read_at' => now()]);

        return ApiResponse::success(null, 'All notifications marked read.');
    }
}
