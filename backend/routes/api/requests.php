<?php

declare(strict_types=1);

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

    // Secure temporary uploads (pre-submit). Rate-limited; session-token gated.
    Route::post('/uploads/start', [UploadController::class, 'start'])->name('uploads.start')
        ->middleware('throttle:20,1');
    Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store')
        ->middleware('throttle:60,1');
    Route::delete('/uploads/{file}', [UploadController::class, 'destroy'])->name('uploads.destroy')
        ->middleware('throttle:60,1');
});
