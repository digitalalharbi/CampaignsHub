<?php

declare(strict_types=1);

use App\Domains\Influencers\Http\Controllers\CollaborationController;
use App\Domains\Influencers\Http\Controllers\InfluencerController;
use Illuminate\Support\Facades\Route;

/*
| Influencer & UGC marketing (INFL-001, ADR 0002).
|
| Gated by `portal:influencers`, so this is a portal boundary and not a menu that appears for some
| account types. Inside it, the ROSTER is tenant-wide — a creator is not owned by a client, and
| hiding them would make an account manager re-add people the agency already works with — while
| COLLABORATIONS are narrowed by the membership's client scope through the same ClientScopeResolver
| every other client-bound surface uses.
*/
Route::middleware(['auth:sanctum', 'tenant', 'portal:influencers'])
    ->prefix('influencers')->name('influencers.')
    ->group(function (): void {
        Route::get('/roster', [InfluencerController::class, 'index'])->name('roster.index');
        Route::post('/roster', [InfluencerController::class, 'store'])->name('roster.store');
        Route::get('/roster/{influencer}', [InfluencerController::class, 'show'])->name('roster.show');
        Route::patch('/roster/{influencer}', [InfluencerController::class, 'update'])->name('roster.update');

        Route::get('/collaborations', [CollaborationController::class, 'index'])->name('collaborations.index');
        Route::post('/collaborations', [CollaborationController::class, 'store'])->name('collaborations.store');
        Route::get('/collaborations/{collaboration}', [CollaborationController::class, 'show'])->name('collaborations.show');
        Route::post('/collaborations/{collaboration}/deliverables', [CollaborationController::class, 'addDeliverable'])
            ->name('collaborations.deliverables.store');
        Route::patch('/collaborations/{collaboration}/deliverables/{deliverable}', [CollaborationController::class, 'updateDeliverable'])
            ->name('collaborations.deliverables.update');
    });
