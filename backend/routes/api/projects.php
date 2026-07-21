<?php

declare(strict_types=1);

use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectMembershipController;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Project management (tenant-scoped; not project-context-bound).
Route::middleware(['auth:sanctum', 'tenant'])->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');
    Route::get('{project}', [ProjectController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '{project}', [ProjectController::class, 'update'])->name('update');
    Route::post('{project}/clone', [ProjectController::class, 'clone'])->name('clone');
    Route::post('{project}/archive', [ProjectController::class, 'archive'])->name('archive');
    Route::post('{project}/restore', [ProjectController::class, 'restore'])->name('restore');
    Route::post('{project}/pause', [ProjectController::class, 'pause'])->name('pause');
    Route::post('{project}/resume', [ProjectController::class, 'resume'])->name('resume');
});

// Project-scoped resources (ResolveProject enforces project isolation).
Route::middleware(['auth:sanctum', 'tenant', 'project'])->prefix('projects/{project}')->name('projects.scoped.')->group(function (): void {
    Route::get('team', [ProjectMembershipController::class, 'index'])->name('team.index');
    Route::post('team', [ProjectMembershipController::class, 'store'])->name('team.store');
    Route::delete('team/{membership}', [ProjectMembershipController::class, 'destroy'])->name('team.destroy');

    // Project-scoped views of tasks and notifications — switching projects changes these too.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
});
