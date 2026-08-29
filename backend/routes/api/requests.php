<?php

declare(strict_types=1);

use App\Domains\Requests\Http\Controllers\ClientPortalController;
use App\Domains\Requests\Http\Controllers\ContactVerificationController;
use App\Domains\Requests\Http\Controllers\Internal\RequestActionsController;
use App\Domains\Requests\Http\Controllers\Internal\RequestJourneyController;
use App\Domains\Requests\Http\Controllers\Internal\RequestsController;
use App\Domains\Requests\Http\Controllers\PublicRequestController;
use App\Domains\Requests\Http\Controllers\UploadController;
use App\Domains\Settings\Http\Controllers\PublicPageSettingsController;
use Illuminate\Support\Facades\Route;

/*
| External Request Portal — PUBLIC endpoints (no auth). Internal dashboard/workflow routes (tenant-scoped,
| permission-gated) are added in the workflow phase.
*/
// PUBLIC editable page content (published only) — lets the marketing homepage and the external portals
// render tenant-edited copy with no code change. No auth: it serves exactly what was published.
Route::get('public/pages/{page}', [PublicPageSettingsController::class, 'publicShow'])
    ->name('public.pages.show')->middleware('throttle:60,1');

Route::prefix('requests')->name('requests.')->group(function (): void {
    Route::get('/meta', [PublicRequestController::class, 'meta'])->name('meta');

    // Contact verification (OTP) — required before a final submit and for portal login.
    Route::post('/verify/start', [ContactVerificationController::class, 'start'])->name('verify.start')
        ->middleware('throttle:otp-request');
    Route::post('/verify/check', [ContactVerificationController::class, 'check'])->name('verify.check')
        ->middleware('throttle:otp-check');

    Route::post('/', [PublicRequestController::class, 'store'])->name('store')
        ->middleware('throttle:requests-intake');
    Route::get('/track/{token}', [PublicRequestController::class, 'track'])->name('track')
        ->middleware('throttle:30,1');
    Route::post('/track/{token}/reply', [PublicRequestController::class, 'reply'])->name('track.reply')
        ->middleware('throttle:20,1');
    Route::get('/track/{token}/files/{file}', [PublicRequestController::class, 'downloadFile'])->name('track.file')
        ->middleware('throttle:60,1');

    // Secure temporary uploads (pre-submit). Rate-limited; session-token gated.
    Route::post('/uploads/start', [UploadController::class, 'start'])->name('uploads.start')
        ->middleware('throttle:20,1');
    Route::post('/uploads', [UploadController::class, 'store'])->name('uploads.store')
        ->middleware('throttle:60,1');
    Route::delete('/uploads/{file}', [UploadController::class, 'destroy'])->name('uploads.destroy')
        ->middleware('throttle:60,1');
});

/*
| External Client Portal — a verified client's dashboard (all their requests, status, messages, files).
| Auth is an httpOnly-cookie session tied to a verified contact; every payload is client-safe.
*/
Route::prefix('client')->name('client.')->group(function (): void {
    Route::post('/login/start', [ClientPortalController::class, 'loginStart'])->name('login.start')
        ->middleware('throttle:otp-request');
    Route::post('/login/verify', [ClientPortalController::class, 'loginVerify'])->name('login.verify')
        ->middleware('throttle:otp-check');
    Route::post('/logout', [ClientPortalController::class, 'logout'])->name('logout');
    Route::get('/session', [ClientPortalController::class, 'session'])->name('session');

    // PORTAL-CLIENT-001: the client spaces this contact may enter. A contact named on two of an
    // agency's clients gets two isolated spaces, never one merged view.
    Route::get('/spaces', [ClientPortalController::class, 'spaces'])->name('spaces.index')
        ->middleware('throttle:120,1');

    // AGENCY-005: the agency's brand as THIS client's space should show it. The space comes from the
    // session, never from a parameter.
    Route::get('/branding', [ClientPortalController::class, 'branding'])->name('branding.show')
        ->middleware('throttle:120,1');

    /*
     * The bytes behind the logo URL `branding()` hands out. `kind`, never an id — the space comes
     * from the session, so the only thing editing this URL can change is which of this client's own
     * marks they receive.
     *
     * Its own throttle ceiling, above the payload's: a page renders one branding payload and may
     * request several marks (header, favicon), and a browser re-requests images on navigation. A
     * shared bucket would tell a paying client their portal is broken because it drew its own logo.
     */
    Route::get('/branding/logo', [ClientPortalController::class, 'brandingLogo'])->name('branding.logo')
        ->middleware('throttle:240,1');

    Route::get('/requests', [ClientPortalController::class, 'index'])->name('requests.index')
        ->middleware('throttle:120,1');
    Route::get('/requests/{reference}', [ClientPortalController::class, 'show'])->name('requests.show')
        ->middleware('throttle:120,1');
    Route::post('/requests/{reference}/reply', [ClientPortalController::class, 'reply'])->name('requests.reply')
        ->middleware('throttle:20,1');
    Route::get('/requests/{reference}/files/{file}', [ClientPortalController::class, 'download'])->name('requests.file')
        ->middleware('throttle:60,1');

    // The client's own journey stage + allowed next self-service actions.
    Route::get('/requests/{reference}/journey', [ClientPortalController::class, 'journey'])->name('requests.journey')
        ->middleware('throttle:120,1');

    // Client-facing content (read-only, client-safe, workspace-scoped to this client): files, campaigns, reports.
    Route::get('/files', [ClientPortalController::class, 'files'])->name('files.index')
        ->middleware('throttle:120,1');
    Route::get('/campaigns', [ClientPortalController::class, 'campaigns'])->name('campaigns.index')
        ->middleware('throttle:120,1');
    Route::get('/reports', [ClientPortalController::class, 'reports'])->name('reports.index')
        ->middleware('throttle:120,1');

    // Client-facing Billing (reuses the Billing domain; every read/write is workspace-scoped to this client).
    Route::get('/quotes', [ClientPortalController::class, 'quotes'])->name('quotes.index')
        ->middleware('throttle:120,1');
    Route::get('/quotes/{quote}', [ClientPortalController::class, 'showQuote'])->name('quotes.show')
        ->middleware('throttle:120,1');
    Route::post('/quotes/{quote}/approve', [ClientPortalController::class, 'approveQuote'])->name('quotes.approve')
        ->middleware('throttle:30,1');
    Route::post('/quotes/{quote}/reject', [ClientPortalController::class, 'rejectQuote'])->name('quotes.reject')
        ->middleware('throttle:30,1');
    Route::get('/invoices', [ClientPortalController::class, 'invoices'])->name('invoices.index')
        ->middleware('throttle:120,1');
    Route::get('/invoices/{invoice}', [ClientPortalController::class, 'showInvoice'])->name('invoices.show')
        ->middleware('throttle:120,1');
    Route::post('/invoices/{invoice}/pay', [ClientPortalController::class, 'payInvoice'])->name('invoices.pay')
        ->middleware('throttle:30,1');

    // Client-facing Messaging (reuses the Messaging domain; author_type=client, workspace-scoped).
    Route::get('/messages', [ClientPortalController::class, 'messages'])->name('messages.index')
        ->middleware('throttle:120,1');
    Route::post('/messages', [ClientPortalController::class, 'openThread'])->name('messages.open')
        ->middleware('throttle:30,1');
    Route::get('/messages/{thread}', [ClientPortalController::class, 'showThread'])->name('messages.show')
        ->middleware('throttle:120,1');
    Route::post('/messages/{thread}', [ClientPortalController::class, 'postThreadMessage'])->name('messages.post')
        ->middleware('throttle:30,1');
});

/*
| Internal requests dashboard (auth + tenant; each controller method enforces its own permission).
*/
Route::middleware(['auth:sanctum', 'tenant', 'portal:agency', 'entitlement:requests'])->prefix('app/requests')->name('app.requests.')->group(function (): void {
    Route::get('/', [RequestsController::class, 'index'])->name('index');
    Route::get('/{id}', [RequestsController::class, 'show'])->name('show');
    Route::patch('/{id}/assign', [RequestActionsController::class, 'assign'])->name('assign');
    Route::patch('/{id}/status', [RequestActionsController::class, 'changeStatus'])->name('status');
    Route::patch('/{id}/journey', [RequestJourneyController::class, 'transition'])->name('journey');
    Route::patch('/{id}/priority', [RequestActionsController::class, 'changePriority'])->name('priority');
    Route::post('/{id}/request-information', [RequestActionsController::class, 'requestInformation'])->name('request-information');
    Route::post('/{id}/internal-note', [RequestActionsController::class, 'addInternalNote'])->name('internal-note');
    Route::post('/{id}/reply', [RequestActionsController::class, 'replyToClient'])->name('reply');
    Route::patch('/{id}/archive', [RequestActionsController::class, 'archive'])->name('archive');
    Route::post('/{id}/convert', [RequestActionsController::class, 'convert'])->name('convert');
    Route::post('/{id}/quote', [RequestActionsController::class, 'raiseQuote'])->name('quote');
});
