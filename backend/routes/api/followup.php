<?php

declare(strict_types=1);

use App\Domains\Notifications\Followup\Http\FollowupController;
use App\Domains\Notifications\Internal\Http\NotificationCenterController;
use Illuminate\Support\Facades\Route;

/*
 * NOTIF-V2-INTERNAL-001 / NOTIF-V2-FOLLOWUP-001 (Owner spec §5, §6) — the notification centre's personal actions and the
 * follow-up assistant. Workspace-level, not per workspace type: one set of routes for an agency, a direct business and a
 * future type; the portal prefix on the front end only decides the path.
 *
 * The plan key is enforced HERE, on the server: `plan.feature:<key>` answers 403 + `meta.upgrade` when the key is off (or, for a
 * write, when it is view-only), and the data stays. Follow-up is operational, so it is for internal members (`portal:app,agency`)
 * — the controller additionally refuses a profile that does not see sensitive events.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers,portal'])->group(function (): void {
    Route::get('notifications/mutes', [NotificationCenterController::class, 'mutes'])->name('notifications.mutes.index');
    Route::middleware('plan.feature:notifications.internal')->group(function (): void {
        Route::post('notifications/{notification}/snooze', [NotificationCenterController::class, 'snooze'])->whereUuid('notification')->name('notifications.snooze');
        Route::post('notifications/{notification}/mute', [NotificationCenterController::class, 'mute'])->whereUuid('notification')->name('notifications.mute');
        Route::post('notifications/mutes/remove', [NotificationCenterController::class, 'unmute'])->name('notifications.mutes.remove');
    });
});

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('followup')->name('followup.')->group(function (): void {
    Route::middleware('plan.feature:followup.tasks')->group(function (): void {
        Route::get('tasks', [FollowupController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [FollowupController::class, 'show'])->whereUuid('task')->name('tasks.show');
        Route::get('tasks/{task}/assignees', [FollowupController::class, 'assignees'])->whereUuid('task')->name('tasks.assignees');
        Route::post('tasks/{task}/snooze', [FollowupController::class, 'snooze'])->whereUuid('task')->name('tasks.snooze');
        Route::post('tasks/{task}/assign', [FollowupController::class, 'assign'])->whereUuid('task')->name('tasks.assign');
        Route::post('tasks/{task}/close', [FollowupController::class, 'close'])->whereUuid('task')->name('tasks.close');
        Route::get('reroute/recipients', [FollowupController::class, 'rerouteRecipients'])->name('reroute.recipients');
        Route::post('reroute', [FollowupController::class, 'reroute'])->middleware('throttle:30,1')->name('reroute');
    });
    Route::middleware('plan.feature:followup.morning_brief')->group(function (): void {
        Route::get('brief', [FollowupController::class, 'brief'])->name('brief');
        Route::get('settings', [FollowupController::class, 'settings'])->name('settings.show');
        Route::match(['put', 'patch'], 'settings', [FollowupController::class, 'updateSettings'])->name('settings.update');
    });
});
