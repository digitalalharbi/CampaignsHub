<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Support\ApiResponse;
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

    // Public brand/domain identity, consumed by the SPA and marketing site.
    Route::get('/brand', fn () => ApiResponse::success([
        'name' => config('brand.name'),
        'domain' => config('brand.domain'),
        'tagline' => config('brand.tagline'),
        'urls' => [
            'marketing' => config('brand.app_url'),
            'app' => config('brand.application_url'),
            'api' => config('brand.api_url'),
            'docs' => config('brand.docs_url'),
            'status' => config('brand.status_url'),
        ],
        'support_email' => config('brand.support_email'),
        'features' => config('brand.features'),
    ], 'Brand identity.'))->name('brand');

    // Domain route files are included here as the platform grows.
    require __DIR__.'/api/identity.php';
    require __DIR__.'/api/crm.php';
    require __DIR__.'/api/integrations.php';
    require __DIR__.'/api/workspaces.php';
    require __DIR__.'/api/projects.php';
});
