<?php

declare(strict_types=1);

use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use Illuminate\Support\Facades\Route;

// Organization settings (tenant-scoped; individual actions enforce settings.manage).
Route::middleware(['auth:sanctum', 'tenant'])->prefix('settings')->name('settings.')->group(function (): void {
    // Disclaimer & methodology central management.
    Route::get('disclaimers', [DisclaimerController::class, 'index'])->name('disclaimers.index');
    Route::put('disclaimers', [DisclaimerController::class, 'update'])->name('disclaimers.update');
    Route::delete('disclaimers/{scope}/{scopeId?}', [DisclaimerController::class, 'destroy'])->name('disclaimers.destroy');
});
