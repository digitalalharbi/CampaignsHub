<?php

declare(strict_types=1);

use App\Domains\Campaigns\Http\Controllers\CampaignActivityController;
use App\Domains\Campaigns\Http\Controllers\CampaignAlertsController;
use App\Domains\Campaigns\Http\Controllers\CampaignAnnotationController;
use App\Domains\Campaigns\Http\Controllers\CampaignCreativesController;
use App\Domains\Campaigns\Http\Controllers\CampaignMetricsController;
use App\Domains\Campaigns\Http\Controllers\CampaignReportsController;
use App\Domains\Campaigns\Http\Controllers\ExternalCampaignController;
use App\Domains\Campaigns\Http\Controllers\UnifiedCampaignController;
use App\Domains\Subscriptions\Http\Middleware\EnsureWithinPlanLimit;
use Illuminate\Support\Facades\Route;

// Project-scoped campaigns (ResolveProject enforces tenant + project isolation; fail-closed 404).
Route::middleware(['auth:sanctum', 'tenant', 'project'])
    ->prefix('projects/{project}')
    ->name('projects.campaigns.')
    ->group(function (): void {
        Route::get('campaigns', [UnifiedCampaignController::class, 'index'])->name('index');
        Route::post('campaigns', [UnifiedCampaignController::class, 'store'])->name('store')
            ->middleware(EnsureWithinPlanLimit::class.':campaigns');
        Route::get('campaigns/{campaign}', [UnifiedCampaignController::class, 'show'])->name('show');
        Route::match(['put', 'patch'], 'campaigns/{campaign}', [UnifiedCampaignController::class, 'update'])->name('update');
        Route::post('campaigns/{campaign}/pause', [UnifiedCampaignController::class, 'pause'])->name('pause');
        Route::post('campaigns/{campaign}/activate', [UnifiedCampaignController::class, 'activate'])->name('activate');
        Route::delete('campaigns/{campaign}', [UnifiedCampaignController::class, 'destroy'])->name('destroy');

        // External-campaign linking.
        Route::get('campaigns/{campaign}/external', [UnifiedCampaignController::class, 'external'])->name('external.index');
        Route::post('campaigns/{campaign}/external', [UnifiedCampaignController::class, 'link'])->name('external.link');
        Route::delete('campaigns/{campaign}/external/{external}', [UnifiedCampaignController::class, 'unlink'])->name('external.unlink');
        Route::get('campaigns/{campaign}/suggestions', [UnifiedCampaignController::class, 'suggestions'])->name('suggestions');

        // Campaign Command Center — per-campaign metrics (scoped to this campaign only).
        Route::get('campaigns/{campaign}/summary', [CampaignMetricsController::class, 'summary'])->name('summary');
        Route::get('campaigns/{campaign}/performance', [CampaignMetricsController::class, 'performance'])->name('performance');
        Route::get('campaigns/{campaign}/platforms', [CampaignMetricsController::class, 'platforms'])->name('platforms');
        Route::get('campaigns/{campaign}/budget', [CampaignMetricsController::class, 'budget'])->name('budget');
        Route::get('campaigns/{campaign}/funnel', [CampaignMetricsController::class, 'funnel'])->name('funnel');
        // CAMPDET-010: recorded conversion events + the real sync history behind the numbers.
        Route::get('campaigns/{campaign}/events', [CampaignMetricsController::class, 'events'])->name('events');
        Route::get('campaigns/{campaign}/sync-log', [CampaignMetricsController::class, 'syncLog'])->name('sync-log');
        Route::get('campaigns/{campaign}/creatives', [CampaignCreativesController::class, 'index'])->name('creatives');
        Route::get('campaigns/{campaign}/activity', [CampaignActivityController::class, 'index'])->name('activity');
        Route::get('campaigns/{campaign}/alerts', [CampaignAlertsController::class, 'index'])->name('alerts');
        Route::get('campaigns/{campaign}/reports', [CampaignReportsController::class, 'index'])->name('reports');
        Route::get('campaigns/{campaign}/annotations', [CampaignAnnotationController::class, 'index'])->name('annotations.index');
        Route::post('campaigns/{campaign}/annotations', [CampaignAnnotationController::class, 'store'])->name('annotations.store');
        Route::match(['put', 'patch'], 'campaigns/{campaign}/annotations/{annotation}', [CampaignAnnotationController::class, 'update'])->name('annotations.update');

        // Project-wide external campaigns (imported by connector sync).
        Route::get('external-campaigns', [ExternalCampaignController::class, 'index'])->name('external-campaigns.index');
    });
