<?php

declare(strict_types=1);

use App\Domains\AI\Http\Controllers\AICredentialController;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Campaigns\Http\Controllers\CreativeLibraryController;
use App\Domains\ClientWorkspaces\Http\Controllers\ClientWorkspaceController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Client workspaces + projects (rentable spaces).
    Route::get('client-workspaces', [ClientWorkspaceController::class, 'index'])->name('client-workspaces.index');
    Route::post('client-workspaces', [ClientWorkspaceController::class, 'store'])->name('client-workspaces.store');
    Route::get('client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'show'])->name('client-workspaces.show');
    Route::match(['put', 'patch'], 'client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'update'])->name('client-workspaces.update');
    Route::delete('client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'archive'])->name('client-workspaces.archive');
    Route::post('client-workspaces/{clientWorkspace}/restore', [ClientWorkspaceController::class, 'restore'])->name('client-workspaces.restore');

    // AI BYOK (masked; secrets never returned).
    Route::get('ai/credentials', [AICredentialController::class, 'index'])->name('ai.credentials.index');
    Route::post('ai/credentials', [AICredentialController::class, 'store'])->name('ai.credentials.store');
    Route::get('ai/credentials/{credential}/health', [AICredentialController::class, 'health'])->name('ai.credentials.health');

    // Notification center.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/deliveries', [NotificationController::class, 'deliveries'])->name('notifications.deliveries');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // Alerting: rules + firing ledger (resolve / snooze).
    Route::get('alerts/rules', [AlertController::class, 'rules'])->name('alerts.rules.index');
    Route::post('alerts/rules', [AlertController::class, 'storeRule'])->name('alerts.rules.store');
    Route::get('alerts/events', [AlertController::class, 'events'])->name('alerts.events.index');
    Route::post('alerts/events/{alertEvent}/resolve', [AlertController::class, 'resolve'])->name('alerts.events.resolve');
    Route::post('alerts/events/{alertEvent}/snooze', [AlertController::class, 'snooze'])->name('alerts.events.snooze');

    // Creative library (tenant-wide content).
    Route::get('creatives', [CreativeLibraryController::class, 'index'])->name('creatives.library');

    // Tasks.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
});
