<?php

declare(strict_types=1);

use App\Domains\AI\Http\Controllers\AICredentialController;
use App\Domains\ClientWorkspaces\Http\Controllers\ClientWorkspaceController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    // Client workspaces + projects (rentable spaces).
    Route::get('client-workspaces', [ClientWorkspaceController::class, 'index'])->name('client-workspaces.index');
    Route::post('client-workspaces', [ClientWorkspaceController::class, 'store'])->name('client-workspaces.store');
    Route::get('client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'show'])->name('client-workspaces.show');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

    // AI BYOK (masked; secrets never returned).
    Route::get('ai/credentials', [AICredentialController::class, 'index'])->name('ai.credentials.index');
    Route::post('ai/credentials', [AICredentialController::class, 'store'])->name('ai.credentials.store');
    Route::get('ai/credentials/{credential}/health', [AICredentialController::class, 'health'])->name('ai.credentials.health');

    // Notification center.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');

    // Tasks.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
});
