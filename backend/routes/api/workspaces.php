<?php

declare(strict_types=1);

use App\Domains\AI\Http\Controllers\AICredentialController;
use App\Domains\Alerts\Http\Controllers\AlertController;
use App\Domains\Campaigns\Http\Controllers\CreativeAnalysisController;
use App\Domains\ClientWorkspaces\Http\Controllers\ClientWorkspaceController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\FilesLibraryController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

/*
 * The agency's client roster (REG-002).
 *
 * `portal:agency`, in its own group, because a client workspace is the one thing that only makes
 * sense for someone managing OTHER people's campaigns. It sat in the shared group below and was
 * therefore reachable from the advertiser portal, which is how `/app` came to carry a Clients
 * section at all.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:agency'])->group(function (): void {
    Route::get('client-workspaces', [ClientWorkspaceController::class, 'index'])->name('client-workspaces.index');
    Route::post('client-workspaces', [ClientWorkspaceController::class, 'store'])->name('client-workspaces.store');
    Route::get('client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'show'])->name('client-workspaces.show');
    Route::match(['put', 'patch'], 'client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'update'])->name('client-workspaces.update');
    Route::delete('client-workspaces/{clientWorkspace}', [ClientWorkspaceController::class, 'archive'])->name('client-workspaces.archive');
    Route::post('client-workspaces/{clientWorkspace}/restore', [ClientWorkspaceController::class, 'restore'])->name('client-workspaces.restore');
});

/*
 * Notifications belong to the PERSON, not to a portal — a creator has to be able to read theirs.
 * Listed for every membership portal rather than left ungated, so adding a sixth portal is a
 * decision someone makes here rather than something that happens by default.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers,portal'])->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/deliveries', [NotificationController::class, 'deliveries'])->name('notifications.deliveries');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
});

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    // AI BYOK (masked; secrets never returned).
    Route::get('ai/credentials', [AICredentialController::class, 'index'])->name('ai.credentials.index');
    Route::post('ai/credentials', [AICredentialController::class, 'store'])->name('ai.credentials.store');
    Route::get('ai/credentials/{credential}/health', [AICredentialController::class, 'health'])->name('ai.credentials.health');

    // Alerting: rules + firing ledger (resolve / snooze).
    Route::get('alerts/rules', [AlertController::class, 'rules'])->name('alerts.rules.index');
    Route::post('alerts/rules', [AlertController::class, 'storeRule'])->name('alerts.rules.store');
    Route::get('alerts/events', [AlertController::class, 'events'])->name('alerts.events.index');
    Route::post('alerts/events/{alertEvent}/resolve', [AlertController::class, 'resolve'])->name('alerts.events.resolve');
    Route::post('alerts/events/{alertEvent}/snooze', [AlertController::class, 'snooze'])->name('alerts.events.snooze');

    // Tasks.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::match(['put', 'patch'], 'tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
});

/*
 * Creative and file libraries. Three portals, because all three declare `content` and `files` —
 * an influencer deliverable is content the same way an ad creative is, and the library is one
 * engine over the tenant's stores rather than three copies with different names.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])->group(function (): void {
    /*
     * The library reads the SAME controller as the project-scoped analysis, deliberately (§15.20).
     *
     * It used to be `CreativeLibraryController`, which summed `creative_daily_metrics` itself and
     * coalesced every null to `0` — so a platform that reports no conversions and a creative that
     * genuinely earned none produced the identical row, and the page contradicted Creative Analysis
     * about the same creative. §15.17 calls a second source an architectural defect rather than a
     * discrepancy, and this is the removal of the one that existed.
     */
    Route::get('creatives', [CreativeAnalysisController::class, 'index'])->name('creatives.library');
    // §15.11 — the dashboard's creative section, over the same query as the library above.
    Route::get('creatives/pulse', [CreativeAnalysisController::class, 'pulse'])->name('creatives.pulse');
    Route::post('creatives/compare', [CreativeAnalysisController::class, 'compare'])->name('creatives.compare');
    /*
     * §15.13 — the same asset across platforms, read as one unit.
     *
     * Before `creatives/{creative}` for the reason above: `groups` would otherwise be looked up as a
     * creative whose id is the word "groups". Reach-scoped rather than project-pinned because the
     * page that lists creatives cannot construct a project id, and the merge derives the project from
     * the selection instead — refusing a selection that spans two.
     */
    Route::get('creatives/groups', [CreativeAnalysisController::class, 'groups'])->name('creatives.groups');
    Route::get('creatives/groups/{group}', [CreativeAnalysisController::class, 'groupShow'])->name('creatives.groups.show')->whereUuid('group');
    Route::post('creatives/group', [CreativeAnalysisController::class, 'merge'])->name('creatives.merge');
    Route::delete('creatives/{creative}/group', [CreativeAnalysisController::class, 'split'])->name('creatives.split')->whereUuid('creative');
    /*
     * §15.6 — the Creative Details page's own address, LAST in this group.
     *
     * After `pulse` and `compare`, or those two would be looked up as creatives whose ids are the
     * words "pulse" and "compare". Reachable without a project id on purpose: the library spans
     * projects and a card does not carry one, so a detail route that required it could not be linked
     * to from the page that lists them. The ceiling is the caller's membership either way.
     */
    /*
     * `whereUuid` so a malformed id is refused by the ROUTER with a 404 rather than reaching Eloquent
     * and returning a 500 «invalid input syntax for type uuid». A route added below this one later
     * cannot forget the check, which a controller guard would allow.
     */
    Route::get('creatives/{creative}', [CreativeAnalysisController::class, 'detail'])->name('creatives.detail')->whereUuid('creative');
    Route::get('files/library', [FilesLibraryController::class, 'index'])->name('files.library');
});
