<?php

declare(strict_types=1);

use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Notifications\Http\Controllers\NotificationRecipientController;
use App\Domains\Notifications\Http\Controllers\TeamNotificationsController;
use App\Domains\Settings\Http\Controllers\BrandingController;
use App\Domains\Settings\Http\Controllers\NotificationPreferenceController;
use App\Domains\Settings\Http\Controllers\OrganizationSettingsController;
use App\Domains\Settings\Http\Controllers\SecurityController;
use App\Domains\Settings\Http\Controllers\TeamController;
use Illuminate\Support\Facades\Route;

// Organization settings (tenant-scoped; individual actions enforce settings.manage where needed).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])->prefix('settings')->name('settings.')->group(function (): void {
    // General (organization profile + display defaults).
    Route::get('organization', [OrganizationSettingsController::class, 'show'])->name('organization.show');
    Route::match(['put', 'patch'], 'organization', [OrganizationSettingsController::class, 'update'])->name('organization.update');

    /*
     * PAGES-001 — the public marketing site is NOT here.
     *
     * The marketing homepage and the three public portals moved to `/admin/settings/public-pages`
     * (routes/api/admin.php). There is one of each and they belong to the platform operator; behind
     * this group they were unreachable from the only console that renders them, and reachable by every
     * tenant administrator — who could rewrite the platform's own front page.
     */

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

    /*
     * Who a manager wants told — MAIL-010. Distinct from the routes above, which are a person's own
     * inbox and need no permission beyond being signed in. These arrange OTHER people, so all four
     * sit behind `settings.manage` — including the listing, which names colleagues and the clients
     * they are attached to.
     *
     * A row created here cannot grant access. `NotificationAudience` resolves every recipient against
     * their own live ceiling at send time, so the worst a mistake here can do is nothing.
     */
    Route::get('notifications/recipients', [NotificationRecipientController::class, 'index'])->name('notifications.recipients.index');
    Route::get('notifications/recipients/assignable', [NotificationRecipientController::class, 'assignable'])->name('notifications.recipients.assignable');
    Route::post('notifications/recipients', [NotificationRecipientController::class, 'store'])->name('notifications.recipients.store');
    Route::delete('notifications/recipients/{recipient}', [NotificationRecipientController::class, 'destroy'])->name('notifications.recipients.destroy');
    // MAIL-012 — who on the team is actually being told anything, and what happened to it.
    Route::get('notifications/team', [TeamNotificationsController::class, 'index'])->name('notifications.team');

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
