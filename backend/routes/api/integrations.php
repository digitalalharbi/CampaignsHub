<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\IntegrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'tenant'])->prefix('integrations')->name('integrations.')->group(function (): void {
    Route::get('/', [IntegrationController::class, 'index'])->name('index');
    Route::get('{key}/health', [IntegrationController::class, 'health'])->name('health');
    Route::post('{key}/connect', [IntegrationController::class, 'connect'])->name('connect');
    Route::post('{key}/sync', [IntegrationController::class, 'sync'])->name('sync');
});
