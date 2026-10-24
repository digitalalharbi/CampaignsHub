<?php

declare(strict_types=1);

use App\Domains\Branding\Http\Controllers\BrandingCenterController;
use App\Domains\Branding\Http\Controllers\ScopedLogoController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped Branding Center: brand assets + settings across scopes. Permission-gated inside the controller
// (branding.view / branding.manage); tenant isolation is enforced by the models' global scope.
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    // LOGO-UPLOAD-001 — the agency's own mark, uploaded as a file.
    Route::get('branding/logo', [ScopedLogoController::class, 'showAgency'])->name('branding.logo.show');
    Route::get('branding/logo/file', [ScopedLogoController::class, 'fileAgency'])->name('branding.logo.file');
    Route::post('branding/logo', [ScopedLogoController::class, 'storeAgency'])->name('branding.logo.store');
    Route::delete('branding/logo', [ScopedLogoController::class, 'destroyAgency'])->name('branding.logo.destroy');

    Route::get('branding/assets', [BrandingCenterController::class, 'assets'])->name('branding.assets.index');
    Route::post('branding/assets', [BrandingCenterController::class, 'upload'])->name('branding.assets.store');
    Route::get('branding/assets/{brandingAsset}/file', [BrandingCenterController::class, 'file'])->name('branding.assets.file');
    Route::delete('branding/assets/{brandingAsset}', [BrandingCenterController::class, 'destroy'])->name('branding.assets.destroy');

    Route::get('branding/settings', [BrandingCenterController::class, 'settings'])->name('branding.settings.show');
    Route::put('branding/settings', [BrandingCenterController::class, 'saveSettings'])->name('branding.settings.update');
});
