<?php

declare(strict_types=1);

use App\Domains\Branding\Http\Controllers\BrandingCenterController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped Branding Center: brand assets + settings across scopes. Permission-gated inside the controller
// (branding.view / branding.manage); tenant isolation is enforced by the models' global scope.
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::get('branding/assets', [BrandingCenterController::class, 'assets'])->name('branding.assets.index');
    Route::post('branding/assets', [BrandingCenterController::class, 'upload'])->name('branding.assets.store');
    Route::get('branding/assets/{brandingAsset}/file', [BrandingCenterController::class, 'file'])->name('branding.assets.file');
    Route::delete('branding/assets/{brandingAsset}', [BrandingCenterController::class, 'destroy'])->name('branding.assets.destroy');

    Route::get('branding/settings', [BrandingCenterController::class, 'settings'])->name('branding.settings.show');
    Route::put('branding/settings', [BrandingCenterController::class, 'saveSettings'])->name('branding.settings.update');
});
