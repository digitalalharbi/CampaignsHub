<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\IntegrationController;
use App\Domains\Integrations\Http\Controllers\ProjectIntegrationController;
use App\Domains\Integrations\Http\Controllers\ProviderConnectionController;
use Illuminate\Support\Facades\Route;

// Tenant-level connector status board + provider connections.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::prefix('integrations')->name('integrations.')->group(function (): void {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::get('{key}/health', [IntegrationController::class, 'health'])->name('health');
        Route::post('{key}/connect', [IntegrationController::class, 'connect'])->name('connect');
        Route::post('{key}/sync', [IntegrationController::class, 'sync'])->name('sync');
    });

    Route::get('connections', [ProviderConnectionController::class, 'index'])->name('connections.index');
    Route::post('connections/{connection}/revoke', [ProviderConnectionController::class, 'revoke'])->name('connections.revoke');
});

// Per-project integrations (ResolveProject enforces project isolation).
Route::middleware(['auth:sanctum', 'tenant', 'project'])
    ->prefix('projects/{project}/integrations')
    ->name('projects.integrations.')
    ->group(function (): void {
        Route::get('/', [ProjectIntegrationController::class, 'index'])->name('index');
        Route::post('connect', [ProjectIntegrationController::class, 'connect'])->name('connect');
        Route::post('bindings', [ProjectIntegrationController::class, 'bind'])->name('bind');
        Route::post('bindings/{binding}/sync', [ProjectIntegrationController::class, 'sync'])->name('sync');
        Route::delete('bindings/{binding}', [ProjectIntegrationController::class, 'detach'])->name('detach');
    });
