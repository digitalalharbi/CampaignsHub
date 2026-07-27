<?php

declare(strict_types=1);

use App\Domains\Requests\Http\Controllers\Internal\RequestActionsController;
use App\Domains\Requests\Http\Controllers\Internal\RequestsController;
use App\Domains\Requests\Http\Controllers\PublicRequestController;
use App\Domains\Requests\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

/*
| External Request Portal — PUBLIC endpoints (no auth). Internal dashboard/workflow routes (tenant-scoped,
| permission-gated) are added in the workflow phase.
*/
Route::prefix('requests')->name('requests.')->group(function (): void {
    Route::get('/meta', [PublicRequestController::class, 'meta'])->name('meta');
    Route::post('/', [PublicRequestController::class, 'store'])->name('store')
        ->middleware('throttle:requests-intake');
    Route::get('/track/{token}', [PublicRequestController::class, 'track'])->name('track')
        ->middleware('throttle:30,1');
    Route::post('/track/{token}/reply', [PublicRequestController::class, 'reply'])->name('track.reply')
        ->middleware('throttle:20,1');
    Route::get('/track/{token}/files/{file}', [PublicRequestController::class, 'downloadFile'])->name('track.file')
        ->middleware('throttle:60,1');

    // Secure temporary uploads (pre-submit). Rate-limited; session-token gated.
    Route::post('/uploads/start', [UploadController::class, 'start'])->name('uploads.start')
        ->middleware('throttle:20,1');
    Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store')
        ->middleware('throttle:60,1');
    Route::delete('/uploads/{file}', [UploadController::class, 'destroy'])->name('uploads.destroy')
        ->middleware('throttle:60,1');
});

/*
| Internal requests dashboard (auth + tenant; each controller method enforces its own permission).
*/
Route::middleware(['auth:sanctum', 'tenant'])->prefix('app/requests')->name('app.requests.')->group(function (): void {
    Route::get('/', [RequestsController::class, 'index'])->name('index');
    Route::get('/{id}', [RequestsController::class, 'show'])->name('show');
    Route::patch('/{id}/assign', [RequestActionsController::class, 'assign'])->name('assign');
    Route::patch('/{id}/status', [RequestActionsController::class, 'changeStatus'])->name('status');
    Route::patch('/{id}/priority', [RequestActionsController::class, 'changePriority'])->name('priority');
    Route::post('/{id}/request-information', [RequestActionsController::class, 'requestInformation'])->name('request-information');
    Route::post('/{id}/internal-note', [RequestActionsController::class, 'addInternalNote'])->name('internal-note');
    Route::post('/{id}/reply', [RequestActionsController::class, 'replyToClient'])->name('reply');
    Route::patch('/{id}/archive', [RequestActionsController::class, 'archive'])->name('archive');
});
