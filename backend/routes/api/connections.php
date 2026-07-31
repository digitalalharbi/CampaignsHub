<?php

declare(strict_types=1);

use App\Domains\Integrations\Http\Controllers\ConnectionCenterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Connection Center (project-scoped)
|--------------------------------------------------------------------------
| Unified connector states + sync history for the current project. State is derived honestly per
| provider; sync history reuses the existing MetricSyncRun. Gated by existing integrations permissions.
|
| NOTE: This file is intentionally NOT wired into routes/api.php here — the orchestrator adds the
| `require __DIR__.'/api/connections.php';` line. Middleware mirrors routes/api/integrations.php
| (auth:sanctum, tenant, project) so the endpoints slot under the /api/v1 group unchanged.
*/

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency', 'project'])
    ->prefix('projects/{project}/connections')
    ->name('projects.connections.')
    ->group(function (): void {
        Route::get('/', [ConnectionCenterController::class, 'index'])->name('index');
        Route::post('{provider}/sync', [ConnectionCenterController::class, 'sync'])->name('sync');
        Route::get('{provider}/history', [ConnectionCenterController::class, 'history'])->name('history');
    });
