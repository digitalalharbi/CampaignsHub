<?php

declare(strict_types=1);

use App\Domains\ContentStudio\Http\Controllers\ContentStudioController;
use Illuminate\Support\Facades\Route;

// CONTENT-STUDIO-001 batch 8 — the studio's storage. Permission and project reach are enforced in the controller.
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('content-studio')->group(function (): void {
    Route::get('families', [ContentStudioController::class, 'families'])->name('content-studio.families');
    Route::get('brand-kit', [ContentStudioController::class, 'brandKit'])->name('content-studio.brand-kit');
    Route::post('brand-kit', [ContentStudioController::class, 'saveBrandKit'])->name('content-studio.brand-kit.save');
    Route::post('sessions', [ContentStudioController::class, 'openSession'])->name('content-studio.sessions.open');
    Route::get('sessions/{session}', [ContentStudioController::class, 'showSession'])->name('content-studio.sessions.show')->whereUuid('session');
    Route::post('sessions/{session}/outputs', [ContentStudioController::class, 'saveOutput'])->name('content-studio.outputs.save')->whereUuid('session');
    Route::post('outputs/{output}/rating', [ContentStudioController::class, 'rate'])->name('content-studio.outputs.rate')->whereUuid('output');
});
