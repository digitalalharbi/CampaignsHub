<?php

declare(strict_types=1);

use App\Domains\Campaigns\Http\Controllers\CreativeAnalysisController;
use App\Domains\Commerce\Http\Controllers\StoreFunnelController;
use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Metrics\Http\Controllers\MetricsController;
use App\Domains\Metrics\Http\Controllers\SavedDashboardViewController;
use App\Domains\Metrics\Http\Controllers\SpendLimitController;
use App\Domains\Metrics\Http\Controllers\SyncRunController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Projects\Http\Controllers\ProjectCapabilityController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectMembershipController;
use App\Domains\Projects\Http\Controllers\ProjectOverviewController;
use App\Domains\Reports\Http\Controllers\LiveReportBuilderController;
use App\Domains\Reports\Http\Controllers\ReportAnnotationController;
use App\Domains\Reports\Http\Controllers\ReportController;
use App\Domains\Reports\Http\Controllers\ReportPrintController;
use App\Domains\Reports\Http\Controllers\ReportScheduleController;
use App\Domains\Reports\Http\Controllers\ReportScopeController;
use App\Domains\Reports\Http\Controllers\ReportShareController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Project management (tenant-scoped; not project-context-bound).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('projects')->name('projects.')->group(function (): void {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store')
        ->middleware(EnsureWithinPlanLimit::class.':projects');
    Route::get('{project}', [ProjectController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '{project}', [ProjectController::class, 'update'])->name('update');
    /*
     * Clone and restore are CREATES as far as a cap is concerned — PAY-AUDIT-001.
     *
     * `usage('projects')` counts live, unarchived projects, so archiving frees a slot and restoring
     * takes one back. A cap guarding only `store` would be trivially walked around by archiving,
     * creating, and restoring.
     */
    Route::post('{project}/clone', [ProjectController::class, 'clone'])->name('clone')
        ->middleware(EnsureWithinPlanLimit::class.':projects');
    Route::post('{project}/archive', [ProjectController::class, 'archive'])->name('archive');
    Route::post('{project}/restore', [ProjectController::class, 'restore'])->name('restore')
        ->middleware(EnsureWithinPlanLimit::class.':projects');
    Route::post('{project}/pause', [ProjectController::class, 'pause'])->name('pause');
    Route::post('{project}/resume', [ProjectController::class, 'resume'])->name('resume');
});

// DASH-010-E: saved dashboard views (persisted per user + tenant; not project-scoped).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('dashboard/saved-views')->name('dashboard.saved-views.')->group(function (): void {
    Route::get('/', [SavedDashboardViewController::class, 'index'])->name('index');
    Route::post('/', [SavedDashboardViewController::class, 'store'])->name('store');
    Route::get('{view}', [SavedDashboardViewController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '{view}', [SavedDashboardViewController::class, 'update'])->name('update');
    Route::delete('{view}', [SavedDashboardViewController::class, 'destroy'])->name('destroy');
    Route::post('{view}/default', [SavedDashboardViewController::class, 'setDefault'])->name('default');
});

// Project-scoped resources (ResolveProject enforces project isolation).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency', 'project'])->prefix('projects/{project}')->name('projects.scoped.')->group(function (): void {
    Route::get('overview', [ProjectOverviewController::class, 'show'])->middleware('project.can:dashboard.view')->name('overview');

    /*
     * TEAM-PROJECT-RBAC-001 — the capability set, for drawing the rail and nothing else.
     *
     * Deliberately NOT behind a `project.can:` of its own: every capability it could require is one
     * this endpoint exists to report on, so gating it would make the rail undrawable for exactly the
     * people whose rail differs. The `project` middleware above has already established that this
     * person may reach the project, and the answer is about themselves — it names no other member and
     * no other project.
     */
    Route::get('capabilities', [ProjectCapabilityController::class, 'index'])->name('capabilities');

    // Effective disclaimer/methodology copy for live surfaces (dashboard/analytics/live report).
    Route::get('disclaimer', [DisclaimerController::class, 'resolve'])->name('disclaimer.resolve');

    // C3 metrics aggregation (read-only; requires campaigns.view).
    Route::get('metrics/summary', [MetricsController::class, 'summary'])->name('metrics.summary');
    Route::get('metrics/timeseries', [MetricsController::class, 'timeseries'])->name('metrics.timeseries');
    Route::get('metrics/drivers', [MetricsController::class, 'drivers'])->name('metrics.drivers');
    Route::get('metrics/platforms', [MetricsController::class, 'platforms'])->name('metrics.platforms');
    Route::get('metrics/accounts', [MetricsController::class, 'accounts'])->name('metrics.accounts');
    Route::get('metrics/campaigns', [MetricsController::class, 'campaigns'])->name('metrics.campaigns');
    /*
     * UX-MULTISELECT-SCALE-001 — the filter's options, searched on the server rather than filled from
     * the full metric breakdown. Declared beside it so the two stay visibly related: one carries
     * figures, the other deliberately does not.
     */
    Route::get('metrics/campaign-options', [MetricsController::class, 'campaignOptions'])->name('metrics.campaign-options');
    /*
     * ANALYTICS-DRILLDOWN-001 — the two rungs beneath a campaign.
     *
     * `{level}` is `ad_set` or `ad`; anything else is refused in the controller rather than
     * answered emptily, because an empty list reads as «this level has no data» and that is a
     * different statement from «there is no such level».
     */
    Route::get('metrics/entities/{level}', [MetricsController::class, 'entities'])->name('metrics.entities');
    // CAMPAIGN-020: side-by-side comparison of 2–5 campaigns of this project.
    Route::get('metrics/compare', [MetricsController::class, 'compare'])->name('metrics.compare');
    Route::get('metrics/funnel', [MetricsController::class, 'funnel'])->name('metrics.funnel');
    /*
     * FUNNEL-001 — «الفانل والمتجر»: the ad half and the store half in one place, with the source of
     * every number attached. Kept apart from `metrics/funnel`, which is the ad-only funnel over
     * `daily_metrics` and has no notion of an order.
     */
    Route::get('commerce/funnel', [StoreFunnelController::class, 'show'])->middleware('project.can:analytics.view')->name('commerce.funnel');
    // REPORT-OBJECTIVE-001: spend and results split by marketing path, Direct and Blended apart.
    Route::get('metrics/objective-performance', [MetricsController::class, 'objectivePerformance'])->name('metrics.objective');
    /*
     * PLATFORM-DECISION-ANALYTICS-001 — each platform's contribution to each marketing path.
     *
     * Separate from `metrics/platforms`, which answers «how is each platform doing» with one set of
     * figures across every objective at once. This one answers «which platform is contributing most
     * to THIS objective», which is the question an operator actually has, and it is the only shape in
     * which platforms may be compared at all.
     */
    Route::get('metrics/platform-objectives', [MetricsController::class, 'platformObjectives'])->name('metrics.platform-objectives');
    Route::get('metrics/objective-leaders', [MetricsController::class, 'objectiveLeaders'])->name('metrics.objective-leaders');
    Route::get('metrics/objective-explanations', [MetricsController::class, 'objectiveExplanations'])->name('metrics.objective-explanations');
    Route::get('metrics/objective-trend', [MetricsController::class, 'objectiveTrend'])->name('metrics.objective-trend');
    Route::get('metrics/budget', [MetricsController::class, 'budget'])->middleware('project.can:budget.view')->name('metrics.budget');
    Route::get('metrics/budget-explanation', [MetricsController::class, 'budgetExplanation'])->middleware('project.can:budget.view')->name('metrics.budget-explanation');
    /*
     * BUDGET-GOVERNANCE-001 — the workspace's OWN limits, which the two routes above are not.
     *
     * `metrics/budget` paces against `unified_campaigns.total_budget`: the plan set inside the ad
     * platform, which the platform itself enforces. These are internal monitoring limits over scopes
     * no single platform can see, and nothing enforces them — every payload says so.
     */
    /*
     * TEAM-PROJECT-RBAC-001 — the first routes to carry a PROJECT capability.
     *
     * Reading a spend limit and changing one are different acts by different people: a management
     * viewer is entitled to know the ceiling, and setting it is a decision with money behind it.
     * `budget.view` and `budget.manage` say so on the route, so the refusal happens on the server
     * for every caller, ours or otherwise.
     */
    Route::get('spend-limits', [SpendLimitController::class, 'index'])->middleware('project.can:budget.view')->name('spend-limits.index');
    Route::post('spend-limits', [SpendLimitController::class, 'store'])->middleware('project.can:budget.manage')->name('spend-limits.store');
    Route::match(['put', 'patch'], 'spend-limits/{spendLimit}', [SpendLimitController::class, 'update'])->middleware('project.can:budget.manage')->name('spend-limits.update');
    Route::delete('spend-limits/{spendLimit}', [SpendLimitController::class, 'destroy'])->middleware('project.can:budget.manage')->name('spend-limits.destroy');
    Route::get('metrics/budget-accounts', [MetricsController::class, 'budgetAccounts'])->middleware('project.can:budget.view')->name('metrics.budget-accounts');
    Route::get('metrics/freshness', [MetricsController::class, 'freshness'])->name('metrics.freshness');
    // NORM-001: what was done to the numbers before they were shown — currency, timezone, attribution,
    // source, objective comparability, and the canonical metric catalogue.
    Route::get('metrics/normalization', [MetricsController::class, 'normalization'])->name('metrics.normalization');
    // REPORT-OBJECTIVE-005: Platform-Reported vs Store-Confirmed, the attribution window each figure
    // was collected under, and what may be summed — the platforms' claims never are.
    Route::get('metrics/attribution', [MetricsController::class, 'attribution'])->name('metrics.attribution');

    // SYNC-001: the sync pipeline's operator surface — what ran, what it produced, what broke.
    /*
     * §15 — the creative as a unit of analysis. Declared before the campaign routes so
     * `creatives/compare` is not read as a creative whose id is the word "compare".
     */
    Route::get('creatives', [CreativeAnalysisController::class, 'index'])->middleware('project.can:campaigns.view')->name('creatives.index');
    // Before `creatives/{creative}` for the same reason as `compare` — otherwise the dashboard
    // section is looked up as a creative whose id is the word "pulse".
    Route::get('creatives/pulse', [CreativeAnalysisController::class, 'pulse'])->middleware('project.can:campaigns.view')->name('creatives.pulse');
    Route::post('creatives/compare', [CreativeAnalysisController::class, 'compare'])->middleware('project.can:campaigns.view')->name('creatives.compare');
    /*
     * The group listing, which this surface was missing while the workspace surface had it.
     *
     * `GET projects/{p}/creatives/groups` fell through to `creatives/{creative}` and was looked up as
     * a creative whose id is the word «groups» — a 500 with a Postgres uuid-cast error, on a URL the
     * groups page constructs whenever it is opened with a project pinned.
     */
    Route::get('creatives/groups', [CreativeAnalysisController::class, 'groups'])->middleware('project.can:campaigns.manage')->name('creatives.groups');
    Route::get('creatives/groups/{group}', [CreativeAnalysisController::class, 'groupShow'])->middleware('project.can:campaigns.manage')->name('creatives.groups.show')->whereUuid('group');
    Route::post('creatives/group', [CreativeAnalysisController::class, 'group'])->middleware('project.can:campaigns.manage')->name('creatives.group');
    /*
     * `whereUuid` on every `{creative}` — the router refuses a malformed id instead of the database.
     *
     * Without it, ANY unmatched word under `creatives/` reaches Eloquent and comes back 500 «invalid
     * input syntax for type uuid», which tells the caller the server is broken when the truth is that
     * there is no such creative. 404 is the honest answer, and a constraint gives it without a line
     * of controller code — so a route added below this one later cannot forget the check.
     */
    Route::get('creatives/{creative}', [CreativeAnalysisController::class, 'show'])->middleware('project.can:campaigns.view')->name('creatives.show')->whereUuid('creative');
    Route::delete('creatives/{creative}/group', [CreativeAnalysisController::class, 'ungroup'])->middleware('project.can:campaigns.manage')->name('creatives.ungroup')->whereUuid('creative');

    Route::get('sync-runs', [SyncRunController::class, 'index'])->middleware('project.can:analytics.view')->name('sync-runs.index');
    Route::post('sync-runs', [SyncRunController::class, 'store'])->middleware('project.can:integrations.manage')->name('sync-runs.store');

    // Reports (project-scoped; reports.view / reports.export).
    /*
     * LIVEREP-002 — build a live client link from a choice (client → project → campaigns → platforms
     * → period → metrics), rather than from an already-generated document.
     */
    Route::get('reports/live/options', [LiveReportBuilderController::class, 'options'])->middleware('project.can:reports.view')->name('reports.live.options');
    Route::post('reports/live', [LiveReportBuilderController::class, 'store'])->middleware('project.can:reports.manage')->name('reports.live.store')
        ->middleware(['project.can:reports.manage', EnsureWithinPlanLimit::class.':reports_per_month']);
    Route::get('reports', [ReportController::class, 'index'])->middleware('project.can:reports.view')->name('reports.index');
    // REPORT-SCHEDULING: the HTTP surface the dispatcher engine never had. Declared BEFORE
    // reports/{report} so "schedules" is not swallowed by the {report} wildcard.
    Route::get('reports/schedules', [ReportScheduleController::class, 'index'])->middleware('project.can:reports.view')->name('reports.schedules.index');
    Route::post('reports/schedules', [ReportScheduleController::class, 'store'])->middleware('project.can:reports.manage')->name('reports.schedules.store');
    Route::match(['put', 'patch'], 'reports/schedules/{schedule}', [ReportScheduleController::class, 'update'])->middleware('project.can:reports.manage')->name('reports.schedules.update');
    Route::post('reports/schedules/{schedule}/toggle', [ReportScheduleController::class, 'toggle'])->middleware('project.can:reports.manage')->name('reports.schedules.toggle');
    Route::post('reports/schedules/{schedule}/run', [ReportScheduleController::class, 'runNow'])->middleware('project.can:reports.manage')->name('reports.schedules.run');
    Route::delete('reports/schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->middleware('project.can:reports.manage')->name('reports.schedules.destroy');

    Route::get('reports/template', [ReportController::class, 'template'])->middleware('project.can:reports.view')->name('reports.template');

    /*
     * §14.5 — what a report covers, and a scope worth using again.
     *
     * `reports/scope/options` and `reports/scope-templates` are declared BEFORE `reports/{report}`
     * for the same reason `reports/schedules` is: a wildcard segment would otherwise swallow them and
     * the picker would ask for a report whose id is the word "scope".
     */
    Route::get('reports/scope/options', [ReportScopeController::class, 'options'])->middleware('project.can:reports.view')->name('reports.scope.options');
    Route::get('reports/scope-templates', [ReportScopeController::class, 'templates'])->middleware('project.can:reports.view')->name('reports.scope-templates.index');
    Route::post('reports/scope-templates', [ReportScopeController::class, 'storeTemplate'])->middleware('project.can:reports.manage')->name('reports.scope-templates.store');
    Route::match(['put', 'patch'], 'reports/scope-templates/{template}', [ReportScopeController::class, 'updateTemplate'])->middleware('project.can:reports.manage')->name('reports.scope-templates.update');
    Route::delete('reports/scope-templates/{template}', [ReportScopeController::class, 'destroyTemplate'])->middleware('project.can:reports.manage')->name('reports.scope-templates.destroy');
    /*
     * Both report creates are capped, and `regenerate` deliberately is not: regenerating writes no
     * new row, so charging a monthly slot for it would bill the customer for the platform's retry.
     */
    Route::post('reports', [ReportController::class, 'store'])->middleware('project.can:reports.manage')->name('reports.store')
        ->middleware(EnsureWithinPlanLimit::class.':reports_per_month');
    Route::get('reports/{report}', [ReportController::class, 'show'])->middleware('project.can:reports.view')->name('reports.show');
    Route::match(['put', 'patch'], 'reports/{report}', [ReportController::class, 'update'])->middleware('project.can:reports.manage')->name('reports.update');
    Route::post('reports/{report}/regenerate', [ReportController::class, 'regenerate'])->middleware('project.can:reports.manage')->name('reports.regenerate');
    Route::get('reports/{report}/scope', [ReportScopeController::class, 'show'])->middleware('project.can:reports.view')->name('reports.scope.show');
    Route::match(['put', 'patch'], 'reports/{report}/scope', [ReportScopeController::class, 'update'])->middleware('project.can:reports.manage')->name('reports.scope.update');
    Route::get('reports/{report}/annotations', [ReportAnnotationController::class, 'index'])->middleware('project.can:reports.view')->name('reports.annotations.index');
    Route::post('reports/{report}/annotations/{annotation}/status', [ReportAnnotationController::class, 'updateStatus'])->middleware('project.can:reports.manage')->name('reports.annotations.status');
    Route::get('reports/{report}/validation', [ReportController::class, 'validation'])->middleware('project.can:reports.view')->name('reports.validation');
    Route::post('reports/{report}/print-token', [ReportPrintController::class, 'issue'])->middleware('project.can:reports.manage')->name('reports.print-token');
    Route::post('reports/{report}/export', [ReportController::class, 'export'])->middleware('project.can:reports.manage')->name('reports.export');
    Route::post('reports/{report}/send', [ReportController::class, 'send'])->middleware('project.can:reports.manage')->name('reports.send');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->middleware('project.can:reports.manage')->name('reports.destroy');

    // Secure client links for a report (reports.share).
    Route::get('reports/{report}/shares', [ReportShareController::class, 'index'])->middleware('project.can:reports.view')->name('reports.shares.index');
    Route::post('reports/{report}/shares', [ReportShareController::class, 'store'])->middleware('project.can:reports.manage')->name('reports.shares.store');
    Route::post('reports/{report}/shares/{share}/revoke', [ReportShareController::class, 'revoke'])->middleware('project.can:reports.manage')->name('reports.shares.revoke');
    Route::post('reports/{report}/shares/{share}/renew', [ReportShareController::class, 'renew'])->middleware('project.can:reports.manage')->name('reports.shares.renew');
    Route::get('reports/{report}/shares/{share}/logs', [ReportShareController::class, 'logs'])->middleware('project.can:reports.view')->name('reports.shares.logs');

    /*
     * TEAM-PROJECT-RBAC-001 — who may change WHO ELSE can see this client.
     *
     * These four decide a project's membership, which is the control that decides every other
     * control. They checked the TENANT permissions `users.invite` / `users.update` / `users.remove`
     * and nothing about the project — so an operator entitled to manage the agency's own staff could
     * add themselves to any client's project and, from there, read its leads.
     *
     * `team.manage` on the project is the narrower question and the right one. The tenant check stays
     * in the controller: adding a person to a project is still an act of user administration, and
     * both must hold.
     *
     * Reading the roster is `team.manage` too rather than a softer capability. A list of who can see
     * a client's customers is itself a disclosure about that client's arrangements.
     */
    Route::get('team', [ProjectMembershipController::class, 'index'])->middleware('project.can:team.manage')->name('team.index');
    Route::post('team', [ProjectMembershipController::class, 'store'])->middleware('project.can:team.manage')->name('team.store');
    Route::match(['put', 'patch'], 'team/{membership}', [ProjectMembershipController::class, 'update'])->middleware('project.can:team.manage')->name('team.update');
    Route::delete('team/{membership}', [ProjectMembershipController::class, 'destroy'])->middleware('project.can:team.manage')->name('team.destroy');

    // Project-scoped views of tasks and notifications — switching projects changes these too.
    Route::get('tasks', [TaskController::class, 'index'])->middleware('project.can:tasks.view')->name('tasks.index');
    Route::get('notifications', [NotificationController::class, 'index'])->middleware('project.can:dashboard.view')->name('notifications.index');
});
