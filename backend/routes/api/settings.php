<?php

declare(strict_types=1);

use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Settings\Http\Controllers\BrandingController;
use App\Domains\Settings\Http\Controllers\NotificationPreferenceController;
use App\Domains\Settings\Http\Controllers\OrganizationSettingsController;
use App\Domains\Settings\Http\Controllers\PublicPageSettingsController;
use App\Domains\Settings\Http\Controllers\SecurityController;
use App\Domains\Settings\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// Organization settings (tenant-scoped; individual actions enforce settings.manage where needed).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])->prefix('settings')->name('settings.')->group(function (): void {
    // General (organization profile + display defaults).
    Route::get('organization', [OrganizationSettingsController::class, 'show'])->name('organization.show');
    Route::match(['put', 'patch'], 'organization', [OrganizationSettingsController::class, 'update'])->name('organization.update');

    // Public surfaces (marketing homepage + external portals) — draft/preview/publish, settings.manage gated.
    Route::get('public-pages', [PublicPageSettingsController::class, 'index'])->name('public-pages.index');
    Route::match(['put', 'patch'], 'public-pages/{page}', [PublicPageSettingsController::class, 'update'])->name('public-pages.update');
    Route::post('public-pages/{page}/publish', [PublicPageSettingsController::class, 'publish'])->name('public-pages.publish');
    Route::post('public-pages/{page}/revert', [PublicPageSettingsController::class, 'revert'])->name('public-pages.revert');

    // Branding.
    Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');
    Route::match(['put', 'patch'], 'branding', [BrandingController::class, 'update'])->name('branding.update');

    // Team & permissions.
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    Route::post('team', [TeamController::class, 'invite'])->name('team.invite');
    Route::put('team/{user}/role', [TeamController::class, 'updateRole'])->name('team.role');
    Route::post('team/{user}/toggle', [TeamController::class, 'toggleStatus'])->name('team.toggle');
    Route::delete('team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

    // Notification preferences (per-user).
    Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
    Route::match(['put', 'patch'], 'notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');

    // Security (account + org policy).
    Route::get('security/activity', [SecurityController::class, 'activity'])->name('security.activity');
    Route::post('security/password', [SecurityController::class, 'changePassword'])->name('security.password');
    Route::post('security/mfa/setup', [SecurityController::class, 'mfaSetup'])->name('security.mfa.setup');
    Route::post('security/mfa/confirm', [SecurityController::class, 'mfaConfirm'])->name('security.mfa.confirm');
    Route::post('security/mfa/disable', [SecurityController::class, 'mfaDisable'])->name('security.mfa.disable');
    Route::get('security/policy', [SecurityController::class, 'showPolicy'])->name('security.policy.show');
    Route::match(['put', 'patch'], 'security/policy', [SecurityController::class, 'updatePolicy'])->name('security.policy.update');

    // Disclaimer & methodology central management.
    Route::get('disclaimers', [DisclaimerController::class, 'index'])->name('disclaimers.index');
    Route::put('disclaimers', [DisclaimerController::class, 'update'])->name('disclaimers.update');
    Route::delete('disclaimers/{scope}/{scopeId?}', [DisclaimerController::class, 'destroy'])->name('disclaimers.destroy');
});
