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

    Route::get('opportunities', [OpportunityController::class, 'index'])->name('opportunities.index');
    Route::get('opportunities/{opportunity}', [OpportunityController::class, 'show'])->name('opportunities.show');
    Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'updateStage'])->name('opportunities.stage');
});
