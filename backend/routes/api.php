<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| All operational endpoints live under /api/v1. A future /api/v2 group can be
| added here without breaking v1.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    // Domain route files are included here as the platform grows.
    require __DIR__.'/api/identity.php';
    require __DIR__.'/api/crm.php';
    require __DIR__.'/api/integrations.php';
});
