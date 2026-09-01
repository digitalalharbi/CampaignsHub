<?php

declare(strict_types=1);

use App\Domains\CRM\Http\Controllers\LeadController;
use App\Domains\CRM\Http\Controllers\OpportunityController;
use Illuminate\Support\Facades\Route;

/*
| CRM endpoints. All require an authenticated, tenant-resolved user.
*/
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->group(function (): void {
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::post('leads', [LeadController::class, 'store'])->name('leads.store');
    Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::match(['put', 'patch'], 'leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::delete('leads/{lead}', [LeadController::class, 'destroy'])->name('leads.destroy');
    Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->name('leads.convert');
    /*
     * LEAD-OPERATIONS-001 — the three things a follow-up team does to a lead.
     *
     * Each one checks the capability on the LEAD's own project and the visibility scope on the lead
     * itself, inside the controller — not on the route, because the project is a fact about the row
     * rather than about the URL, and a route parameter would let a caller choose which project's
     * permission they are judged by.
     */
    Route::post('leads/{lead}/stage', [LeadController::class, 'advance'])->name('leads.stage');
    Route::post('leads/{lead}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::post('leads/{lead}/follow-up', [LeadController::class, 'followUp'])->name('leads.follow-up');

    Route::get('opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
    Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])->name('opportunities.show');
    Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'updateStage'])->name('opportunities.stage');
});
