<?php

declare(strict_types=1);

use App\Domains\Agency\Http\Controllers\AgencyDashboardController;
use App\Domains\Agency\Http\Controllers\AgencyTeamController;
use Illuminate\Support\Facades\Route;

/*
| The agency portal (ADR 0002).
|
| Gated by `portal:agency`, so holding an advertiser membership in the same tenant does not open it —
| a portal is an authorisation boundary, not a menu. Everything inside is additionally narrowed by the
| membership's client scope, so an account manager responsible for three clients sees three clients'
| worth of everything.
|
| Deliberately THIN. The agency does not get its own copies of clients, projects, campaigns, reports or
| finance — those engines already exist under /app and are scope-aware through ClientAccess, which the
| agency portal shares. Duplicating them here would give the product two implementations of the same
| business rules to keep in step, which is exactly what ADR 0002 rules out.
*/
Route::middleware(['auth:sanctum', 'tenant', 'portal:agency'])
    ->prefix('agency')->name('agency.')
    ->group(function (): void {
        Route::get('/dashboard', AgencyDashboardController::class)->name('dashboard');

        // Team & client scopes. Three verbs rather than one save, because "add a client" and
        // "redefine what this person can see" are different decisions and must stay different calls.
        Route::get('/team', [AgencyTeamController::class, 'index'])->name('team.index');
        Route::post('/team/{membership}/scopes', [AgencyTeamController::class, 'addScopes'])->name('team.scopes.add');
        Route::put('/team/{membership}/scopes', [AgencyTeamController::class, 'replaceScopes'])->name('team.scopes.replace');
        Route::delete('/team/{membership}/scopes/{client}', [AgencyTeamController::class, 'removeScope'])->name('team.scopes.remove');
    });
