<?php

declare(strict_types=1);

use App\Domains\Campaigns\Http\Controllers\CampaignActivityController;
use App\Domains\Campaigns\Http\Controllers\CampaignAlertsController;
use App\Domains\Campaigns\Http\Controllers\CampaignAnnotationController;
use App\Domains\Campaigns\Http\Controllers\CampaignCreativesController;
use App\Domains\Campaigns\Http\Controllers\CampaignMetricsController;
use App\Domains\Campaigns\Http\Controllers\CampaignReportsController;
use App\Domains\Campaigns\Http\Controllers\CampaignStructureController;
use App\Domains\Campaigns\Http\Controllers\ExternalCampaignController;
use App\Domains\Campaigns\Http\Controllers\RelatedEntitiesController;
use App\Domains\Campaigns\Http\Controllers\UnifiedCampaignController;
use Illuminate\Support\Facades\Route;

/*
 * Project-scoped campaigns (ResolveProject enforces tenant + project isolation; fail-closed 404).
 *
 * TEAM-PROJECT-RBAC-001 — every route here also states the project CAPABILITY it needs.
 *
 * Isolation and authorisation are different questions and this group only answered the first: a
 * reader was proved to be asking about a project they can reach, and then anything their tenant role
 * allowed was allowed here. A tenant role spans every client an agency has, and a project membership
 * exists precisely to narrow that — so a media buyer added to one client's project could pause
 * another client's campaigns.
 *
 * Reads take `campaigns.view` and writes take `campaigns.manage`. Stated per route rather than on the
 * group, because the group mixes both and a group-level capability would have to be the weaker of
 * the two — which is how a delete ends up guarded by a read.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency', 'project'])
    ->prefix('projects/{project}')
    ->name('projects.campaigns.')
    ->group(function (): void {
        Route::get('campaigns', [UnifiedCampaignController::class, 'index'])->middleware('project.can:campaigns.view')->name('index');
        /*
         * Campaigns are NOT metered — LAUNCH-LIMITS-001.
         *
         * The plan is sold on what it costs us to hold: connected ad accounts, projects, seats and
         * client workspaces. Campaigns inside a connected account are the customer's own work, and
         * charging for the number of them would penalise using the product for what it is for —
         * somebody testing five variants would pay more than somebody running one badly.
         *
         * The gate is removed rather than left mounted against an absent cap: a mount that only
         * passes because no plan happens to publish a `campaigns` limit is one admin edit away from
         * silently becoming a paywall nobody decided on.
         */
        Route::post('campaigns', [UnifiedCampaignController::class, 'store'])->middleware('project.can:campaigns.manage')->name('store');
        Route::get('campaigns/{campaign}', [UnifiedCampaignController::class, 'show'])->middleware('project.can:campaigns.view')->name('show');
        Route::match(['put', 'patch'], 'campaigns/{campaign}', [UnifiedCampaignController::class, 'update'])->middleware('project.can:campaigns.manage')->name('update');
        Route::post('campaigns/{campaign}/pause', [UnifiedCampaignController::class, 'pause'])->middleware('project.can:campaigns.manage')->name('pause');
        Route::post('campaigns/{campaign}/activate', [UnifiedCampaignController::class, 'activate'])->middleware('project.can:campaigns.manage')->name('activate');
        Route::delete('campaigns/{campaign}', [UnifiedCampaignController::class, 'destroy'])->middleware('project.can:campaigns.manage')->name('destroy');

        // External-campaign linking.
        Route::get('campaigns/{campaign}/external', [UnifiedCampaignController::class, 'external'])->middleware('project.can:campaigns.view')->name('external.index');
        Route::post('campaigns/{campaign}/external', [UnifiedCampaignController::class, 'link'])->middleware('project.can:campaigns.manage')->name('external.link');
        Route::delete('campaigns/{campaign}/external/{external}', [UnifiedCampaignController::class, 'unlink'])->middleware('project.can:campaigns.manage')->name('external.unlink');
        Route::get('campaigns/{campaign}/suggestions', [UnifiedCampaignController::class, 'suggestions'])->middleware('project.can:campaigns.view')->name('suggestions');

        // Campaign Command Center — per-campaign metrics (scoped to this campaign only).
        Route::get('campaigns/{campaign}/summary', [CampaignMetricsController::class, 'summary'])->middleware('project.can:campaigns.view')->name('summary');
        Route::get('campaigns/{campaign}/performance', [CampaignMetricsController::class, 'performance'])->middleware('project.can:campaigns.view')->name('performance');
        Route::get('campaigns/{campaign}/platforms', [CampaignMetricsController::class, 'platforms'])->middleware('project.can:campaigns.view')->name('platforms');
        Route::get('campaigns/{campaign}/budget', [CampaignMetricsController::class, 'budget'])->middleware('project.can:campaigns.view')->name('budget');
        Route::get('campaigns/{campaign}/funnel', [CampaignMetricsController::class, 'funnel'])->middleware('project.can:campaigns.view')->name('funnel');
        // CAMPDET-010: recorded conversion events + the real sync history behind the numbers.
        Route::get('campaigns/{campaign}/events', [CampaignMetricsController::class, 'events'])->middleware('project.can:campaigns.view')->name('events');
        Route::get('campaigns/{campaign}/sync-log', [CampaignMetricsController::class, 'syncLog'])->middleware('project.can:campaigns.view')->name('sync-log');
        // CAMPDET-010: the real ad-set / ad hierarchy beneath the campaign.
        Route::get('campaigns/{campaign}/structure', [CampaignStructureController::class, 'index'])->middleware('project.can:campaigns.view')->name('structure');
        // STRUCT-001: queue the same discovery the scheduler runs, for this campaign's accounts.
        Route::post('campaigns/{campaign}/structure/sync', [CampaignStructureController::class, 'sync'])
            ->middleware('project.can:campaigns.manage')
            ->middleware('throttle:12,1')->name('structure.sync');
        // XREL-001: everything this campaign is connected to, one click away.
        Route::get('campaigns/{campaign}/related', [RelatedEntitiesController::class, 'campaign'])->middleware('project.can:campaigns.view')->name('related');
        Route::get('campaigns/{campaign}/creatives', [CampaignCreativesController::class, 'index'])->middleware('project.can:campaigns.view')->name('creatives');
        Route::get('campaigns/{campaign}/activity', [CampaignActivityController::class, 'index'])->middleware('project.can:campaigns.view')->name('activity');
        Route::get('campaigns/{campaign}/alerts', [CampaignAlertsController::class, 'index'])->middleware('project.can:campaigns.view')->name('alerts');
        Route::get('campaigns/{campaign}/reports', [CampaignReportsController::class, 'index'])->middleware('project.can:campaigns.view')->name('reports');
        Route::get('recommendations', [CampaignAnnotationController::class, 'projectIndex'])->middleware('project.can:campaigns.view')->name('recommendations.index');
        Route::get('campaigns/{campaign}/annotations', [CampaignAnnotationController::class, 'index'])->middleware('project.can:campaigns.view')->name('annotations.index');
        Route::post('campaigns/{campaign}/annotations', [CampaignAnnotationController::class, 'store'])->middleware('project.can:campaigns.manage')->name('annotations.store');
        Route::match(['put', 'patch'], 'campaigns/{campaign}/annotations/{annotation}', [CampaignAnnotationController::class, 'update'])->middleware('project.can:campaigns.manage')->name('annotations.update');

        // Project-wide external campaigns (imported by connector sync).
        Route::get('external-campaigns', [ExternalCampaignController::class, 'index'])->middleware('project.can:campaigns.view')->name('external-campaigns.index');
    });
