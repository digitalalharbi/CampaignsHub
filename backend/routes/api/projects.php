<?php

declare(strict_types=1);

use App\Domains\Disclaimers\Http\Controllers\DisclaimerController;
use App\Domains\Metrics\Http\Controllers\MetricsController;
use App\Domains\Metrics\Http\Controllers\SavedDashboardViewController;
use App\Domains\Notifications\Http\Controllers\NotificationController;
use App\Domains\Projects\Http\Controllers\ProjectController;
use App\Domains\Projects\Http\Controllers\ProjectMembershipController;
use App\Domains\Projects\Http\Controllers\ProjectOverviewController;
use App\Domains\Reports\Http\Controllers\ReportAnnotationController;
use App\Domains\Reports\Http\Controllers\ReportController;
use App\Domains\Reports\Http\Controllers\ReportPrintController;
use App\Domains\Reports\Http\Controllers\ReportShareController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use App\Domains\Tasks\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

// Project management (tenant-scoped; not project-context-bound).
Route::middleware(['auth:sanctum', 'tenant'])->prefix('projects')->name('projects.')->group(function (): void {
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
Route::middleware(['auth:sanctum', 'tenant'])->prefix('dashboard/saved-views')->name('dashboard.saved-views.')->group(function (): void {
    Route::get('/', [SavedDashboardViewController::class, 'index'])->name('index');
    Route::post('/', [SavedDashboardViewController::class, 'store'])->name('store');
    Route::get('{view}', [SavedDashboardViewController::class, 'show'])->name('show');
    Route::match(['put', 'patch'], '{view}', [SavedDashboardViewController::class, 'update'])->name('update');
    Route::delete('{view}', [SavedDashboardViewController::class, 'destroy'])->name('destroy');
    Route::post('{view}/default', [SavedDashboardViewController::class, 'setDefault'])->name('default');
});

// Project-scoped resources (ResolveProject enforces project isolation).
Route::middleware(['auth:sanctum', 'tenant', 'project'])->prefix('projects/{project}')->name('projects.scoped.')->group(function (): void {
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
    Route::get('metrics/budget', [MetricsController::class, 'budget'])->name('metrics.budget');
    Route::get('metrics/freshness', [MetricsController::class, 'freshness'])->name('metrics.freshness');

    // Reports (project-scoped; reports.view / reports.export).
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/template', [ReportController::class, 'template'])->name('reports.template');
    Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');
    Route::match(['put', 'patch'], 'reports/{report}', [ReportController::class, 'update'])->name('reports.update');
    Route::post('reports/{report}/regenerate', [ReportController::class, 'regenerate'])->name('reports.regenerate');
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
    Route::get('reports/{report}/shares/{share}/logs', [ReportShareController::class, 'logs'])->name('reports.shares.logs');

    Route::get('team', [ProjectMembershipController::class, 'index'])->name('team.index');
    Route::post('team', [ProjectMembershipController::class, 'store'])->name('team.store');
    Route::match(['put', 'patch'], 'team/{membership}', [ProjectMembershipController::class, 'update'])->name('team.update');
    Route::delete('team/{membership}', [ProjectMembershipController::class, 'destroy'])->name('team.destroy');

    // Project-scoped views of tasks and notifications — switching projects changes these too.
    Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
});
