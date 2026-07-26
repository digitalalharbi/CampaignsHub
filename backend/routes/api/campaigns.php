<?php

declare(strict_types=1);

use App\Domains\Campaigns\Http\Controllers\CampaignActivityController;
use App\Domains\Campaigns\Http\Controllers\CampaignMetricsController;
use App\Domains\Campaigns\Http\Controllers\ExternalCampaignController;
use App\Domains\Campaigns\Http\Controllers\UnifiedCampaignController;
use Illuminate\Support\Facades\Route;

// Project-scoped campaigns (ResolveProject enforces tenant + project isolation; fail-closed 404).
Route::middleware(['auth:sanctum', 'tenant', 'project'])
    ->prefix('projects/{project}')
    ->name('projects.campaigns.')
    ->group(function (): void {
        Route::get('campaigns', [UnifiedCampaignController::class, 'index'])->name('index');
        Route::post('campaigns', [UnifiedCampaignController::class, 'store'])->name('store');
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
        Route::get('campaigns/{campaign}/activity', [CampaignActivityController::class, 'index'])->name('activity');

        // Project-wide external campaigns (imported by connector sync).
        Route::get('external-campaigns', [ExternalCampaignController::class, 'index'])->name('external-campaigns.index');
    });
