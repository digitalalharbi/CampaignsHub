<?php

declare(strict_types=1);

use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientActivityController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientAnalyticsController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientFilesController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientManagementController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientReportsController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientsController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientTaxonomyController;
use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientTeamController;
use Illuminate\Support\Facades\Route;

/*
| Client portfolio + command center (auth + tenant; permission + per-client access enforced in controllers).
*/
Route::middleware(['auth:sanctum', 'tenant'])->prefix('app/clients')->name('app.clients.')->group(function (): void {
    // Enum catalogue for classification/settings dropdowns.
    Route::get('/meta/taxonomy', ClientTaxonomyController::class)->name('taxonomy');

    Route::get('/', [ClientsController::class, 'index'])->name('index');
    Route::get('/{client}', [ClientsController::class, 'show'])->name('show');

    // Classification management + settings + archive lifecycle.
    Route::patch('/{client}/classification', [ClientManagementController::class, 'updateClassification'])->name('classification');
    Route::patch('/{client}/settings', [ClientManagementController::class, 'updateSettings'])->name('settings');
    Route::post('/{client}/archive', [ClientManagementController::class, 'archive'])->name('archive');
    Route::post('/{client}/restore', [ClientManagementController::class, 'restore'])->name('restore');

    // Command-center tabs backed by existing engines (metrics/reports/…).
    Route::get('/{client}/analytics', ClientAnalyticsController::class)->name('analytics');

    Route::get('/{client}/reports', [ClientReportsController::class, 'index'])->name('reports.index');
    Route::post('/{client}/reports', [ClientReportsController::class, 'store'])->name('reports.store');
    Route::post('/{client}/reports/{report}/share', [ClientReportsController::class, 'share'])->name('reports.share');
    Route::post('/{client}/reports/{report}/shares/{share}/revoke', [ClientReportsController::class, 'revoke'])->name('reports.revoke');

    // Team access.
    Route::get('/{client}/team', [ClientTeamController::class, 'index'])->name('team.index');
    Route::get('/{client}/team/assignable', [ClientTeamController::class, 'assignable'])->name('team.assignable');
    Route::post('/{client}/team', [ClientTeamController::class, 'store'])->name('team.store');
    Route::patch('/{client}/team/{user}', [ClientTeamController::class, 'update'])->name('team.update');
    Route::delete('/{client}/team/{user}', [ClientTeamController::class, 'destroy'])->name('team.destroy');

    // Files (read-only over existing private stores) + secure streamed download.
    Route::get('/{client}/files', [ClientFilesController::class, 'index'])->name('files.index');
    Route::get('/{client}/files/{source}/{id}/download', [ClientFilesController::class, 'download'])->name('files.download');

    // Activity timeline (assembled from real events).
    Route::get('/{client}/activity', ClientActivityController::class)->name('activity');
});
