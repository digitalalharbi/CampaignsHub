<?php

use App\Domains\Influencers\Http\Controllers\AttributionController;
use App\Domains\Reports\Http\Controllers\SharePreviewController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The influencer tracking redirect (INFL-003).
 *
 * On the WEB routes rather than the API, because this is a hop a stranger's browser makes — the
 * creator's follower tapping a link in a story. There is no session, no tenant and no JSON, and the
 * short path is deliberate: it gets read aloud, printed, and typed off a phone screen.
 *
 * This is also what makes the click count honest. The platform serves the hop itself, so the number
 * beside a link is something it measured rather than something somebody typed in later — which is
 * exactly the distinction a discount code cannot make, and why that one is labelled differently.
 */
Route::get('/t/{code}', [AttributionController::class, 'redirect'])
    ->where('code', '[A-Za-z0-9\-]{1,64}')
    ->name('influencers.track');

/*
 * REPORT-TITLE-METADATA-001 — the preview card a shared report link renders as when pasted.
 *
 * A WEB route, like the influencer redirect above and for the same reason: this is fetched by a
 * stranger's crawler with no session, no tenant and no JSON. The edge sends only crawler
 * user-agents here; a real browser gets the SPA and never reaches it.
 */
Route::get('/r/{token}', [SharePreviewController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{16,64}')
    ->name('reports.share.preview');
