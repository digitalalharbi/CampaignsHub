<?php

declare(strict_types=1);

use App\Domains\Campaigns\Http\Controllers\CreativeAnalysisController;
use App\Domains\Commerce\Http\Controllers\StoreFunnelController;
use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Metrics\Http\Controllers\MetricsController;
use App\Domains\Metrics\Http\Controllers\SavedDashboardViewController;
use App\Domains\Metrics\Http\Controllers\SyncRunController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
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
    Route::post('{project}/clone', [ProjectController::class, 'clone'])->name('clone');
    Route::post('{project}/archive', [ProjectController::class, 'archive'])->name('archive');
    Route::post('{project}/restore', [ProjectController::class, 'restore'])->name('restore');
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
    Route::get('overview', [ProjectOverviewController::class, 'show'])->name('overview');

    // Effective disclaimer/methodology copy for live surfaces (dashboard/analytics/live report).
    Route::get('disclaimer', [DisclaimerController::class, 'resolve'])->name('disclaimer.resolve');

    // C3 metrics aggregation (read-only; requires campaigns.view).
    Route::get('metrics/summary', [MetricsController::class, 'summary'])->name('metrics.summary');
    Route::get('metrics/timeseries', [MetricsController::class, 'timeseries'])->name('metrics.timeseries');
    Route::get('metrics/platforms', [MetricsController::class, 'platforms'])->name('metrics.platforms');
    Route::get('metrics/campaigns', [MetricsController::class, 'campaigns'])->name('metrics.campaigns');
    // CAMPAIGN-020: side-by-side comparison of 2–5 campaigns of this project.
    Route::get('metrics/compare', [MetricsController::class, 'compare'])->name('metrics.compare');
    Route::get('metrics/funnel', [MetricsController::class, 'funnel'])->name('metrics.funnel');
    /*
     * FUNNEL-001 — «الفانل والمتجر»: the ad half and the store half in one place, with the source of
     * every number attached. Kept apart from `metrics/funnel`, which is the ad-only funnel over
     * `daily_metrics` and has no notion of an order.
     */
    Route::get('commerce/funnel', [StoreFunnelController::class, 'show'])->name('commerce.funnel');
    // REPORT-OBJECTIVE-001: spend and results split by marketing path, Direct and Blended apart.
    Route::get('metrics/objective-performance', [MetricsController::class, 'objectivePerformance'])->name('metrics.objective');
    Route::get('metrics/budget', [MetricsController::class, 'budget'])->name('metrics.budget');
    Route::get('metrics/freshness', [MetricsController::class, 'freshness'])->name('metrics.freshness');
    // NORM-001: what was done to the numbers before they were shown — currency, timezone, attribution,
    // source, objective comparability, and the canonical metric catalogue.
    Route::get('metrics/normalization', [MetricsController::class, 'normalization'])->name('metrics.normalization');

    // SYNC-001: the sync pipeline's operator surface — what ran, what it produced, what broke.
    /*
     * §15 — the creative as a unit of analysis. Declared before the campaign routes so
     * `creatives/compare` is not read as a creative whose id is the word "compare".
     */
    Route::get('creatives', [CreativeAnalysisController::class, 'index'])->name('creatives.index');
    // Before `creatives/{creative}` for the same reason as `compare` — otherwise the dashboard
    // section is looked up as a creative whose id is the word "pulse".
    Route::get('creatives/pulse', [CreativeAnalysisController::class, 'pulse'])->name('creatives.pulse');
    Route::post('creatives/compare', [CreativeAnalysisController::class, 'compare'])->name('creatives.compare');
    Route::post('creatives/group', [CreativeAnalysisController::class, 'group'])->name('creatives.group');
    Route::get('creatives/{creative}', [CreativeAnalysisController::class, 'show'])->name('creatives.show');
    Route::delete('creatives/{creative}/group', [CreativeAnalysisController::class, 'ungroup'])->name('creatives.ungroup');

    Route::get('sync-runs', [SyncRunController::class, 'index'])->name('sync-runs.index');
    Route::post('sync-runs', [SyncRunController::class, 'store'])->name('sync-runs.store');

    // Reports (project-scoped; reports.view / reports.export).
    /*
     * LIVEREP-002 — build a live client link from a choice (client → project → campaigns → platforms
     * → period → metrics), rather than from an already-generated document.
     */
    Route::get('reports/live/options', [LiveReportBuilderController::class, 'options'])->name('reports.live.options');
    Route::post('reports/live', [LiveReportBuilderController::class, 'store'])->name('reports.live.store');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    // REPORT-SCHEDULING: the HTTP surface the dispatcher engine never had. Declared BEFORE
    // reports/{report} so "schedules" is not swallowed by the {report} wildcard.
    Route::get('reports/schedules', [ReportScheduleController::class, 'index'])->name('reports.schedules.index');
    Route::post('reports/schedules', [ReportScheduleController::class, 'store'])->name('reports.schedules.store');
    Route::match(['put', 'patch'], 'reports/schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('reports.schedules.update');
    Route::post('reports/schedules/{schedule}/toggle', [ReportScheduleController::class, 'toggle'])->name('reports.schedules.toggle');
    Route::post('reports/schedules/{schedule}/run', [ReportScheduleController::class, 'runNow'])->name('reports.schedules.run');
    Route::delete('reports/schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('reports.schedules.destroy');

    Route::get('reports/template', [ReportController::class, 'template'])->name('reports.template');

    /*
     * §14.5 — what a report covers, and a scope worth using again.
     *
     * `reports/scope/options` and `reports/scope-templates` are declared BEFORE `reports/{report}`
     * for the same reason `reports/schedules` is: a wildcard segment would otherwise swallow them and
     * the picker would ask for a report whose id is the word "scope".
     */
    Route::get('reports/scope/options', [ReportScopeController::class, 'options'])->name('reports.scope.options');
    Route::get('reports/scope-templates', [ReportScopeController::class, 'templates'])->name('reports.scope-templates.index');
    Route::post('reports/scope-templates', [ReportScopeController::class, 'storeTemplate'])->name('reports.scope-templates.store');
    Route::match(['put', 'patch'], 'reports/scope-templates/{template}', [ReportScopeController::class, 'updateTemplate'])->name('reports.scope-templates.update');
    Route::delete('reports/scope-templates/{template}', [ReportScopeController::class, 'destroyTemplate'])->name('reports.scope-templates.destroy');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::match(['put', 'patch'], 'reports/{report}', [ReportController::class, 'update'])->name('reports.update');
    Route::post('reports/{report}/regenerate', [ReportController::class, 'regenerate'])->name('reports.regenerate');
    Route::get('reports/{report}/scope', [ReportScopeController::class, 'show'])->name('reports.scope.show');
    Route::match(['put', 'patch'], 'reports/{report}/scope', [ReportScopeController::class, 'update'])->name('reports.scope.update');
    Route::get('reports/{report}/annotations', [ReportAnnotationController::class, 'index'])->name('reports.annotations.index');
    Route::post('reports/{report}/annotations/{annotation}/status', [ReportAnnotationController::class, 'updateStatus'])->name('reports.annotations.status');
    Route::get('reports/{report}/validation', [ReportController::class, 'validation'])->name('reports.validation');
    Route::post('reports/{report}/print-token', [ReportPrintController::class, 'issue'])->name('reports.print-token');
    Route::post('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('reports/{report}/send', [ReportController::class, 'send'])->name('reports.send');
    Route::delete('reports/{report}', [ReportController::class, 'destroy'])->name('reports.destroy');

    // Secure client links for a report (reports.share).
    Route::get('reports/{report}/shares', [ReportShareController::class, 'index'])->name('reports.shares.index');
    Route::post('reports/{report}/shares', [ReportShareController::class, 'store'])->name('reports.shares.store');
    Route::post('reports/{report}/shares/{share}/revoke', [ReportShareController::class, 'revoke'])->name('reports.shares.revoke');
    Route::post('reports/{report}/shares/{share}/renew', [ReportShareController::class, 'renew'])->name('reports.shares.renew');
    Route::get('reports/{report}/shares/{share}/logs', [ReportShareController::class, 'logs'])->name('reports.shares.logs');

    Route::get('team', [ProjectMembershipController::class, 'index'])->name('team.index');
    Route::post('team', [ProjectMembershipController::class, 'store'])->name('team.store');
    Route::match(['put', 'patch'], 'team/{membership}', [ProjectMembershipController::class, 'update'])->name('team.update');
    Route::delete('team/{membership}', [ProjectMembershipController::class, 'destroy'])->name('team.destroy');

    // Project-scoped views of tasks and notifications — switching projects changes these too.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
});
