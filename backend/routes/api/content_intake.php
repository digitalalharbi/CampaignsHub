<?php

declare(strict_types=1);

use App\Domains\ContentIntake\Http\Controllers\ContentIntakeController;
use App\Domains\ContentIntake\Http\Controllers\ContentOverviewController;
use Illuminate\Support\Facades\Route;

// CONTENT-INTAKE-001 — the quality gate for new content. Permission and project reach are enforced in the controller;
// the status moves only through IntakeTransitions.
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::get('content-overview', ContentOverviewController::class)->name('content-overview');
    Route::get('content-intake', [ContentIntakeController::class, 'index'])->name('content-intake.index');
    Route::get('content-intake/follow-up', [ContentIntakeController::class, 'followUp'])->name('content-intake.follow-up');
    Route::post('content-intake', [ContentIntakeController::class, 'store'])->name('content-intake.store');
    Route::post('content-intake/drive/{link}/sync', [ContentIntakeController::class, 'driveSync'])->name('content-intake.drive-sync')->whereUuid('link');
    Route::get('content-intake/{item}', [ContentIntakeController::class, 'show'])->name('content-intake.show')->whereUuid('item');
    Route::post('content-intake/{item}', [ContentIntakeController::class, 'update'])->name('content-intake.update')->whereUuid('item');
    Route::post('content-intake/{item}/transition', [ContentIntakeController::class, 'transition'])->name('content-intake.transition')->whereUuid('item');
    Route::post('content-intake/{item}/recheck', [ContentIntakeController::class, 'recheck'])->name('content-intake.recheck')->whereUuid('item');
    Route::get('content-intake/{item}/file', [ContentIntakeController::class, 'file'])->name('content-intake.file')->whereUuid('item');
});
