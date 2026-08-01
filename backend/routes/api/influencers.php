<?php

declare(strict_types=1);

use App\Domains\Influencers\Http\Controllers\AttributionController;
use App\Domains\Influencers\Http\Controllers\CollaborationController;
use App\Domains\Influencers\Http\Controllers\CreatorController;
use App\Domains\Influencers\Http\Controllers\InfluencerController;
use App\Domains\Influencers\Http\Controllers\NominationController;
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
        Route::post('/roster/{influencer}/access', [InfluencerController::class, 'grantAccess'])->name('roster.access.grant');
        Route::delete('/roster/{influencer}/access', [InfluencerController::class, 'revokeAccess'])->name('roster.access.revoke');

        Route::get('/collaborations', [CollaborationController::class, 'index'])->name('collaborations.index');
        Route::post('/collaborations', [CollaborationController::class, 'store'])->name('collaborations.store');
        Route::get('/collaborations/{collaboration}', [CollaborationController::class, 'show'])->name('collaborations.show');
        Route::post('/collaborations/{collaboration}/deliverables', [CollaborationController::class, 'addDeliverable'])
            ->name('collaborations.deliverables.store');
        Route::patch('/collaborations/{collaboration}/deliverables/{deliverable}', [CollaborationController::class, 'updateDeliverable'])
            ->name('collaborations.deliverables.update');
        Route::post('/collaborations/{collaboration}/send-terms', [CollaborationController::class, 'sendTerms'])
            ->name('collaborations.send-terms');

        /*
         | Nominations — the decision, kept whichever way it went (INFL-003).
         |
         | Proposing needs `influencers.manage`; DECIDING needs `influencers.approve`, which is a
         | separate permission so a shortlist is not a rubber stamp its own author holds.
         */
        Route::get('/nominations', [NominationController::class, 'index'])->name('nominations.index');
        Route::post('/nominations', [NominationController::class, 'store'])->name('nominations.store');
        Route::post('/nominations/{nomination}/decide', [NominationController::class, 'decide'])->name('nominations.decide');
        Route::delete('/nominations/{nomination}', [NominationController::class, 'withdraw'])->name('nominations.withdraw');
        Route::post('/nominations/{nomination}/collaboration', [NominationController::class, 'convert'])
            ->name('nominations.convert');

        /*
         | Attribution — how one creator's traffic is told apart from another's (INFL-003).
         |
         | A link's clicks are counted by this platform, which serves the redirect. A discount code's
         | redemptions happen in the brand's own store and are reported, never measured — the
         | response says which is which on every row.
         */
        Route::get('/collaborations/{collaboration}/tracking', [AttributionController::class, 'index'])
            ->name('collaborations.tracking.index');
        Route::post('/collaborations/{collaboration}/tracking', [AttributionController::class, 'store'])
            ->name('collaborations.tracking.store');
        Route::patch('/tracking/{asset}/redemptions', [AttributionController::class, 'recordRedemptions'])
            ->name('tracking.redemptions');

        Route::get('/deliverables/{deliverable}/results', [AttributionController::class, 'results'])
            ->name('deliverables.results.index');
        Route::post('/deliverables/{deliverable}/results', [AttributionController::class, 'recordResult'])
            ->name('deliverables.results.store');

        /*
         | The CREATOR's own surface (INFL-002).
         |
         | Same portal, opposite side of the agreement — so these sit inside the same portal gate but
         | require none of the `influencers.*` permissions above. A creator holds no agency permission
         | and therefore already gets a 403 from every route before this comment; what they may do
         | here follows from CreatorAccess and from whether terms were actually offered.
         |
         | Every route is scoped to WHO IS ASKING. There is deliberately no `{influencer}` segment on
         | any of them: an id in the URL is an authorisation check that has to be right on every
         | route, and one missed check is one creator reading another's fee.
         */
        Route::prefix('me')->name('me.')->group(function (): void {
            Route::get('/', [CreatorController::class, 'me'])->name('profile');
            Route::get('/collaborations', [CreatorController::class, 'collaborations'])->name('collaborations');
            Route::get('/collaborations/{collaboration}', [CreatorController::class, 'show'])->name('collaborations.show');
            Route::post('/collaborations/{collaboration}/respond', [CreatorController::class, 'respond'])->name('collaborations.respond');
            Route::post('/collaborations/{collaboration}/deliverables/{deliverable}/submit', [CreatorController::class, 'submitDeliverable'])
                ->name('deliverables.submit');
        });
    });
