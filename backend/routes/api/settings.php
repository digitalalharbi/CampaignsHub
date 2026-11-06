<?php

declare(strict_types=1);

use App\Domains\Accounts\Http\Controllers\CapabilityController;
use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Notifications\Http\Controllers\NotificationRecipientController;
use App\Domains\Notifications\Http\Controllers\TeamNotificationsController;
use App\Domains\Settings\Http\Controllers\BrandingController;
use App\Domains\Settings\Http\Controllers\NotificationChannelTestController;
use App\Domains\Settings\Http\Controllers\NotificationPreferenceController;
use App\Domains\Settings\Http\Controllers\NotificationVerifyController;
use App\Domains\Settings\Http\Controllers\OrganizationSettingsController;
use App\Domains\Settings\Http\Controllers\SecurityController;
use App\Domains\Settings\Http\Controllers\TeamController;
use App\Domains\Settings\Http\Controllers\WhatsAppOptInController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
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

    // CAP-REGISTRY-001 — which modules this workspace has and where each is switched on or off.
    Route::get('capabilities', [CapabilityController::class, 'index'])->name('capabilities.index');
    Route::put('capabilities/{module}', [CapabilityController::class, 'update'])->name('capabilities.update');
    Route::delete('capabilities/{module}', [CapabilityController::class, 'destroy'])->name('capabilities.destroy');

    // Branding.
    Route::get('branding', [BrandingController::class, 'show'])->name('branding.show');
    Route::match(['put', 'patch'], 'branding', [BrandingController::class, 'update'])->name('branding.update');

    // Team & permissions.
    Route::get('team', [TeamController::class, 'index'])->name('team.index');
    // The seat is taken at the INVITE, not at acceptance — `usage('team_members')` counts pending
    // invitations for exactly this reason (PAY-AUDIT-001).
    Route::post('team', [TeamController::class, 'invite'])->name('team.invite')
        ->middleware(EnsureWithinPlanLimit::class.':team_members');
    /*
     * TEAM-INVITE-001 — withdraw an invitation that has not been accepted.
     *
     * Declared BEFORE `team/{user}`: a literal segment must be registered ahead of the wildcard, or
     * `DELETE team/invitations/…` is matched as a member id and refused as «no such member».
     */
    Route::delete('team/invitations/{invitation}', [TeamController::class, 'revokeInvitation'])->name('team.invitations.revoke');
    Route::put('team/{user}/role', [TeamController::class, 'updateRole'])->name('team.role');
    Route::post('team/{user}/toggle', [TeamController::class, 'toggleStatus'])->name('team.toggle');
    Route::delete('team/{user}', [TeamController::class, 'destroy'])->name('team.destroy');

    // Notification preferences (per-user).
    Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
    Route::match(['put', 'patch'], 'notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
    // NOTIFICATION-CHANNELS-001 — NOT `notifications/test`: that path was withdrawn on purpose (MailTestSendTest) and stays 404. This one
    // sends only to the signed-in person's OWN proved address, on the mail-test limiter; the platform mailbox stays the admin's alone.
    // One test notification to the signed-in person, per channel; and their WhatsApp opt-in.
    Route::post('notifications/channel-test', NotificationChannelTestController::class)->middleware('throttle:mail-test-send')->name('notifications.channel-test');
    // NOTIFICATION-ENROLMENT-001 — «send me this now»: one real digest / one marked sample alert, to the signed-in person's own proved address only.
    Route::post('notifications/digest-now/{kind}', [NotificationVerifyController::class, 'digest'])->middleware('throttle:mail-test-send')->name('notifications.digest-now');
    Route::post('notifications/sample-alert', [NotificationVerifyController::class, 'alert'])->middleware('throttle:mail-test-send')->name('notifications.sample-alert');
    Route::post('notifications/whatsapp/start', [WhatsAppOptInController::class, 'start'])->middleware('throttle:5,1')->name('notifications.whatsapp.start');
    Route::post('notifications/whatsapp/confirm', [WhatsAppOptInController::class, 'confirm'])->middleware('throttle:10,1')->name('notifications.whatsapp.confirm');
    Route::delete('notifications/whatsapp', [WhatsAppOptInController::class, 'destroy'])->name('notifications.whatsapp.destroy');
    // §17.4.5 — a manager's preview of the templates. Sending one is the platform admin's alone (EMAIL-TEST-SEND-001).
    Route::get('notifications/previews', [NotificationPreferenceController::class, 'previews'])->name('notifications.previews');
    Route::get('notifications/previews/{key}', [NotificationPreferenceController::class, 'preview'])->name('notifications.preview');

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
    // EMAIL-SETTINGS-DEPTH-001 — what was actually sent, and what failed, from the ledgers that
    // already record it.
    Route::get('notifications/deliveries', [TeamNotificationsController::class, 'deliveries'])->name('notifications.deliveries');

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
