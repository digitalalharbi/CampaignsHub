<?php

declare(strict_types=1);

use App\Domains\Funding\Http\Controllers\FundingController;
use Illuminate\Support\Facades\Route;

/*
 * FIN-LOOP-001 — approved project budgets. Agency staff only; never the client portal in this batch.
 *
 * The parameter is `{funded}`, not `{project}`: the global money layer strips money BY KEY name from a
 * `{project}` answer, which on this payload would remove some figures and leave the rest (`remaining`,
 * `approved`). The controller instead refuses the whole budget (404) to a membership that withholds money
 * — the same rule, applied completely. FundingLedgerTest asserts it.
 */
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('funding')->name('funding.')->group(function (): void {
    Route::get('disclaimer', [FundingController::class, 'disclaimer'])->name('disclaimer');
    Route::get('/', [FundingController::class, 'index'])->name('index');
    Route::post('payments', [FundingController::class, 'store'])->middleware('throttle:30,1')->name('payments.store');
    Route::get('projects/{funded}', [FundingController::class, 'show'])->whereUuid('funded')->name('projects.show');
    Route::get('projects/{funded}/months/{month}', [FundingController::class, 'month'])->whereUuid('funded')->name('projects.month');
    Route::get('export', [FundingController::class, 'export'])->middleware('throttle:20,1')->name('export');
    Route::post('projects/{funded}/adjust', [FundingController::class, 'adjust'])->whereUuid('funded')->middleware('throttle:30,1')->name('projects.adjust');
    Route::post('projects/{funded}/reallocate', [FundingController::class, 'reallocate'])->whereUuid('funded')->middleware('throttle:30,1')->name('projects.reallocate');
    Route::post('projects/{funded}/void', [FundingController::class, 'void'])->whereUuid('funded')->middleware('throttle:30,1')->name('projects.void');
    Route::post('projects/{funded}/undo', [FundingController::class, 'undo'])->whereUuid('funded')->middleware('throttle:30,1')->name('projects.undo');
    Route::patch('projects/{funded}', [FundingController::class, 'update'])->whereUuid('funded')->name('projects.update');
});
