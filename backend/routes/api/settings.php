<?php

declare(strict_types=1);

use App\Domains\Accounts\Http\Controllers\CapabilityController;
use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Notifications\Engine\Http\Controllers\ConsentController;
use App\Domains\Notifications\Engine\Http\Controllers\DeliveryLogController;
use App\Domains\Notifications\Engine\Http\Controllers\WorkspaceNotificationSettingsController;
use App\Domains\Notifications\Engine\Http\Controllers\WorkspacePlanUsageController;
use App\Domains\Notifications\External\Http\ExternalPreviewController;
use App\Domains\Notifications\External\Http\ExternalRecipientsController;
use App\Domains\Notifications\External\Http\ExternalReviewsController;
use App\Domains\Notifications\Http\Controllers\NotificationRecipientController;
use App\Domains\Notifications\Http\Controllers\TeamNotificationsController;
use App\Domains\Settings\Http\Controllers\AccessCenterController;
use App\Domains\Settings\Http\Controllers\BrandingController;
use App\Domains\Settings\Http\Controllers\ExternalRecipientController;
use App\Domains\Settings\Http\Controllers\NotificationChannelTestController;
use App\Domains\Settings\Http\Controllers\NotificationPreferenceController;
use App\Domains\Settings\Http\Controllers\NotificationVerifyController;
use App\Domains\Settings\Http\Controllers\OrganizationSettingsController;
use App\Domains\Settings\Http\Controllers\RolesController;
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

    // TEAM-ROLE-PRESETS-001 — the ready-made roles and their checkboxes; settings.manage, audited, grant-only-what-you-hold.
    Route::get('roles', [RolesController::class, 'index'])->name('roles.index');
    Route::put('roles/{slug}', [RolesController::class, 'update'])->where('slug', '[a-z-]+')->name('roles.update');
    Route::post('roles/{slug}/reset', [RolesController::class, 'reset'])->where('slug', '[a-z-]+')->name('roles.reset');

    /*
     * ACCESS-CENTER-001 — «الفريق والنطاقات»: categories, invitations with per-invitation areas, member overrides, history.
     * settings.manage in the controller; categories are the owner's alone; grant-only-what-you-hold; every change audited.
     */
    Route::get('access', [AccessCenterController::class, 'index'])->name('access.index');
    Route::post('access/categories', [AccessCenterController::class, 'storeCategory'])->middleware('throttle:30,1')->name('access.categories.store');
    Route::put('access/categories/{slug}', [AccessCenterController::class, 'updateCategory'])->where('slug', '[a-z0-9-]+')->name('access.categories.update');
    Route::delete('access/categories/{slug}', [AccessCenterController::class, 'destroyCategory'])->where('slug', '[a-z0-9-]+')->name('access.categories.destroy');
    Route::post('access/invitations', [AccessCenterController::class, 'invite'])->middleware(['throttle:20,1', EnsureWithinPlanLimit::class.':team_members'])->name('access.invitations.store');
    Route::put('access/invitations/{invitation}', [AccessCenterController::class, 'updateInvitation'])->where('invitation', '[0-9A-Za-z]+')->name('access.invitations.update');
    Route::post('access/invitations/{invitation}/resend', [AccessCenterController::class, 'resend'])->where('invitation', '[0-9A-Za-z]+')->middleware('throttle:10,1')->name('access.invitations.resend');
    Route::put('access/members/{user}/overrides', [AccessCenterController::class, 'updateOverrides'])->whereUuid('user')->name('access.members.overrides');
    // ACCESS-SECTIONS-001 — edit a member after the invitation (category, areas, sections, clients) in one save; the access log.
    Route::put('access/members/{user}', [AccessCenterController::class, 'updateMember'])->whereUuid('user')->name('access.members.update');
    Route::get('access/audit', [AccessCenterController::class, 'audit'])->name('access.audit');
    Route::get('access/history', [AccessCenterController::class, 'history'])->name('access.history');

    // Notification preferences (per-user).
    Route::get('notifications', [NotificationPreferenceController::class, 'show'])->name('notifications.show');
    Route::match(['put', 'patch'], 'notifications', [NotificationPreferenceController::class, 'update'])->name('notifications.update');
    // NOTIFICATION-CHANNELS-001 — NOT `notifications/test`: that path was withdrawn on purpose (MailTestSendTest) and stays 404. This one
    // sends only to the signed-in person's OWN proved address, on the mail-test limiter; the platform mailbox stays the admin's alone.
    // One test notification to the signed-in person, per channel; and their WhatsApp opt-in.
    Route::post('notifications/channel-test', NotificationChannelTestController::class)->middleware('throttle:mail-test-send')->name('notifications.channel-test');
    // NOTIFICATION-ENROLMENT-001 — «send me this now»: one real digest / one marked sample alert, to the signed-in person's own proved address only.
    Route::post('notifications/digest-now/{kind}', [NotificationVerifyController::class, 'digest'])->middleware('throttle:mail-test-send')->name('notifications.digest-now');
    // SPEND-SUMMARY-EXTERNAL-RECIPIENTS-001 — recipients OUTSIDE the platform for the budget follow-up; `settings.manage` in the controller.
    Route::get('notifications/external-recipients', [ExternalRecipientController::class, 'index'])->name('notifications.external.index');
    Route::post('notifications/external-recipients', [ExternalRecipientController::class, 'store'])->middleware('throttle:30,1')->name('notifications.external.store');
    Route::get('notifications/external-recipients/projects', [ExternalRecipientController::class, 'projects'])->name('notifications.external.projects');
    Route::post('notifications/external-recipients/test', [ExternalRecipientController::class, 'test'])->middleware('throttle:mail-test-send')->name('notifications.external.test');
    Route::match(['put', 'patch'], 'notifications/external-recipients/{recipient}', [ExternalRecipientController::class, 'update'])->whereUuid('recipient')->name('notifications.external.update');
    Route::delete('notifications/external-recipients/{recipient}', [ExternalRecipientController::class, 'destroy'])->whereUuid('recipient')->name('notifications.external.destroy');
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
    // NOTIF-V2-ENGINE-001 — the shared notifications engine, workspace level (one component for every workspace type).
    // NOTIF-V2-SETTINGS-UI-001 — the data of the one workspace-level notification settings page (any workspace type).
    Route::get('workspace/notifications/settings', [WorkspaceNotificationSettingsController::class, 'show'])->name('workspace.notifications.settings.show');
    Route::match(['put', 'patch'], 'workspace/notifications/settings', [WorkspaceNotificationSettingsController::class, 'update'])->middleware('throttle:60,1')->name('workspace.notifications.settings.update');
    Route::get('workspace/notifications/kpis', [WorkspaceNotificationSettingsController::class, 'kpis'])->name('workspace.notifications.kpis');
    Route::get('workspace/notifications/entitlements', [WorkspacePlanUsageController::class, 'entitlements'])->name('workspace.notifications.entitlements');
    Route::get('workspace/notifications/usage', [WorkspacePlanUsageController::class, 'usage'])->name('workspace.notifications.usage');
    // The proof of notice: read-only, no write route exists (the tables refuse UPDATE/DELETE too).
    Route::get('workspace/notifications/log', [DeliveryLogController::class, 'index'])->name('workspace.notifications.log');
    Route::get('workspace/notifications/log/export', [DeliveryLogController::class, 'export'])->middleware('throttle:10,1')->name('workspace.notifications.log.export');
    Route::get('workspace/notifications/subscription-log', [DeliveryLogController::class, 'subscriptions'])->name('workspace.notifications.subscription-log');
    // «أرسل طلبات التأكيد»: preview, then one explicit owner send repeating the previewed count. Never automatic.
    Route::get('workspace/notifications/confirmation-requests', [ConsentController::class, 'preview'])->name('workspace.notifications.confirmation-requests.preview');
    Route::post('workspace/notifications/confirmation-requests', [ConsentController::class, 'send'])->middleware(['plan.feature:notifications.external', 'throttle:3,1'])->name('workspace.notifications.confirmation-requests.send');
    Route::post('workspace/notifications/recipients/{recipient}/approve-internally', [ConsentController::class, 'approveInternally'])->whereUuid('recipient')->middleware('plan.feature:notifications.external')->name('workspace.notifications.recipients.approve-internally');
    // NOTIF-V2-EXTERNAL-001 — the external pipeline's JSON API for the settings tabs and the wizard (Q-notes.md).
    Route::prefix('workspace/notifications/external')->name('workspace.notifications.external.')->group(function (): void {
        Route::get('options', [ExternalRecipientsController::class, 'options'])->name('options');
        Route::post('period-check', [ExternalRecipientsController::class, 'periodCheck'])->name('period-check');
        Route::get('kpis', [ExternalRecipientsController::class, 'kpis'])->name('kpis');
        Route::get('settings', [ExternalRecipientsController::class, 'showSettings'])->name('settings.show');
        Route::put('settings', [ExternalRecipientsController::class, 'updateSettings'])->middleware('plan.feature:notifications.external')->name('settings.update');
        Route::get('recipients', [ExternalRecipientsController::class, 'index'])->name('recipients.index');
        Route::post('recipients', [ExternalRecipientsController::class, 'store'])->middleware('plan.feature:notifications.external')->name('recipients.store');
        Route::get('recipients/{recipient}', [ExternalRecipientsController::class, 'show'])->whereUuid('recipient')->name('recipients.show');
        Route::patch('recipients/{recipient}', [ExternalRecipientsController::class, 'update'])->whereUuid('recipient')->middleware('plan.feature:notifications.external')->name('recipients.update');
        Route::post('recipients/{recipient}/{action}', [ExternalRecipientsController::class, 'recipientAction'])->whereUuid('recipient')->whereIn('action', ['pause', 'resume', 'cancel'])->middleware('plan.feature:notifications.external')->name('recipients.action');
        Route::post('recipients/{recipient}/subscriptions', [ExternalRecipientsController::class, 'storeSubscription'])->whereUuid('recipient')->middleware('plan.feature:notifications.external')->name('subscriptions.store');
        Route::patch('subscriptions/{subscription}', [ExternalRecipientsController::class, 'updateSubscription'])->whereUuid('subscription')->middleware('plan.feature:notifications.external')->name('subscriptions.update');
        Route::post('subscriptions/{subscription}/{action}', [ExternalRecipientsController::class, 'subscriptionAction'])->whereUuid('subscription')->whereIn('action', ['pause', 'resume', 'cancel', 'duplicate'])->middleware('plan.feature:notifications.external')->name('subscriptions.action');
        Route::post('preview', [ExternalPreviewController::class, 'preview'])->middleware('throttle:30,1')->name('preview');
        Route::post('test-send', [ExternalPreviewController::class, 'testSend'])->middleware(['plan.feature:notifications.external', 'throttle:5,1'])->name('test-send');
        Route::post('send-now', [ExternalPreviewController::class, 'sendNow'])->middleware(['plan.feature:notifications.external', 'throttle:10,1'])->name('send-now');
        Route::get('reviews', [ExternalReviewsController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{review}/send', [ExternalReviewsController::class, 'send'])->whereUuid('review')->middleware('plan.feature:notifications.external')->name('reviews.send');
        Route::post('reviews/{review}/skip', [ExternalReviewsController::class, 'skip'])->whereUuid('review')->name('reviews.skip');
    });

    // Security (account + org policy).
    Route::get('security/activity', [SecurityController::class, 'activity'])->name('security.activity');
    Route::post('security/password', [SecurityController::class, 'changePassword'])->name('security.password');
    // TWO-STEP-001 — the second step moved to `/me/two-factor/*` (three methods, recovery codes, enforced at sign-in).
    Route::get('security/policy', [SecurityController::class, 'showPolicy'])->name('security.policy.show');
    Route::match(['put', 'patch'], 'security/policy', [SecurityController::class, 'updatePolicy'])->name('security.policy.update');

    // Disclaimer & methodology central management.
    Route::get('disclaimers', [DisclaimerController::class, 'index'])->name('disclaimers.index');
    Route::put('disclaimers', [DisclaimerController::class, 'update'])->name('disclaimers.update');
    Route::delete('disclaimers/{scope}/{scopeId?}', [DisclaimerController::class, 'destroy'])->name('disclaimers.destroy');
});
