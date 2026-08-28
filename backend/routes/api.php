<?php

declare(strict_types=1);

use App\Domains\Legal\Http\Controllers\DataDeletionController;
use App\Domains\Legal\Http\Controllers\PublicIntakeController;
use App\Domains\Legal\Http\Controllers\PublicLegalController;
use App\Domains\Reports\Http\Controllers\PublicReportController;
use App\Domains\Reports\Http\Controllers\ReportDownloadController;
use App\Domains\Reports\Http\Controllers\ReportPrintController;
use App\Domains\Taxonomy\Http\Controllers\PublicPaidServiceController;
use App\Http\Controllers\Dev\DevStatusController;
use App\Http\Controllers\HealthController;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| All operational endpoints live under /api/v1. A future /api/v2 group can be
| added here without breaking v1.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', [HealthController::class, 'health'])->name('health');
    Route::get('/ready', [HealthController::class, 'ready'])->name('ready');

    // DEV-only live environment status (hard-blocked in production; no secrets exposed).
    if (! app()->environment('production')) {
        Route::get('/dev/status', [DevStatusController::class, 'show'])->name('dev.status');
    }

    /*
     * LEGAL-001 — the operator's identity and policy versions, without a session.
     *
     * Public is a requirement here, not a convenience: every platform whose OAuth review this
     * product must pass fetches the privacy and terms URLs itself, unauthenticated, from this
     * domain. A policy surface behind a login fails those reviews with no explanation.
     */
    Route::get('/legal', PublicLegalController::class)->name('legal');

    /*
     * LEGAL-002 — what a visitor can actually send us.
     *
     * Unauthenticated by necessity: the people most likely to need the contact form are not customers
     * yet, and somebody asking for their data to be deleted may have lost access to the account. Rate
     * limited per IP because a public write endpoint without one is an invitation, and each carries a
     * honeypot field in the controller.
     */
    Route::post('/contact', [PublicIntakeController::class, 'contact'])
        ->middleware('throttle:5,1')->name('contact');
    Route::post('/support/tickets', [PublicIntakeController::class, 'support'])
        ->middleware('throttle:5,1')->name('support.tickets');
    Route::post('/data-requests', [PublicIntakeController::class, 'dataRequest'])
        ->middleware('throttle:data-subject-request')->name('data-requests');

    /*
     * LEGAL-DELETE-001 — the deletion flow behind https://campaignshub.io/data-deletion.
     *
     * Public by necessity: somebody asking to be deleted has usually already lost access, or never
     * had an account and appears only inside a client's data. Requiring a sign-in would put a wall
     * in front of the one right that has to work when everything else has failed.
     *
     * `verify` and `status` carry the OTP-check throttle rather than the gentler intake one — they
     * take a code and a reference, and both are guessable at volume if nothing counts the attempts.
     *
     * `submit` is limited by the SUBJECT of the request, not by the address it arrives from
     * (LEGAL-THROTTLE-001) — a literal per-IP throttle rationed a legal right by whoever else happened
     * to share the router.
     */
    Route::post('/data-deletion', [DataDeletionController::class, 'submit'])
        ->middleware('throttle:data-subject-request')->name('data-deletion.submit');
    Route::post('/data-deletion/verify', [DataDeletionController::class, 'verify'])
        ->middleware('throttle:otp-check')->name('data-deletion.verify');
    Route::post('/data-deletion/status', [DataDeletionController::class, 'status'])
        ->middleware('throttle:otp-check')->name('data-deletion.status');

    /*
     * The machine-readable callback a platform posts to. CSRF is excluded for it in bootstrap/app.php
     * for the same reason the webhooks are: a provider's server has no CSRF token and never will,
     * and this endpoint verifies an HMAC over the signed request before it writes anything.
     */
    Route::post('/webhooks/data-deletion/{provider}', [DataDeletionController::class, 'callback'])
        ->middleware('throttle:60,1')->name('data-deletion.callback');

    // Public, token-gated, expiring report download (the shareable secure link).
    Route::get('/reports/download/{token}', ReportDownloadController::class)->name('reports.download');

    // Public secure client report links (token-gated, sanitized, logged).
    Route::get('/reports/shared/{token}', [PublicReportController::class, 'show'])->name('reports.shared.show');
    // LIVEREP-001 — figures recomputed on request, inside the link's own ceiling. Same token, same
    // password gate, same access log; a snapshot link answers this with 409 rather than empty data.
    Route::get('/reports/shared/{token}/live', [PublicReportController::class, 'live'])->name('reports.shared.live');
    Route::get('/reports/shared/{token}/download/{format}', [PublicReportController::class, 'download'])->name('reports.shared.download');
    /*
     * BRANDING-HIERARCHY-001 — the identity this link carries, addressed by the TOKEN alone.
     *
     * No asset id, tenant id or scope is accepted: an endpoint that takes one is an endpoint
     * somebody will enumerate, and a shared report link is exactly where a stranger has a URL and
     * time. The token that proves the reader may see the report is the only thing that selects the
     * logo.
     */
    Route::get('/reports/shared/{token}/branding', [PublicReportController::class, 'sharedBranding'])->name('reports.shared.branding');
    Route::get('/reports/shared/{token}/branding/logo', [PublicReportController::class, 'sharedBrandingLogo'])->name('reports.shared.branding.logo');

    /*
     * §15.12 — the creative sections of a client's report.
     *
     * `summary` and `comparison` are registered BEFORE the `{creative}` wildcard, or the router would
     * read them as creative ids and answer «this content is not available on this link» for two
     * addresses that are not creatives at all.
     */
    // ATTRIB-VIS-001 — refuses with 404 unless the link's own settings carry the section.
    Route::get('/reports/shared/{token}/attribution', [PublicReportController::class, 'attribution'])->name('reports.shared.attribution');
    Route::get('/reports/shared/{token}/creatives', [PublicReportController::class, 'creatives'])->name('reports.shared.creatives');
    Route::get('/reports/shared/{token}/creatives/summary', [PublicReportController::class, 'creativeSummary'])->name('reports.shared.creatives.summary');
    Route::get('/reports/shared/{token}/creatives/comparison', [PublicReportController::class, 'creativeComparison'])->name('reports.shared.creatives.comparison');
    Route::get('/reports/shared/{token}/creatives/{creative}', [PublicReportController::class, 'creative'])->name('reports.shared.creatives.show');

    // Print pipeline: token-gated snapshot for the headless-Chromium print route (no session).
    Route::get('/reports/print/{token}', [ReportPrintController::class, 'data'])->name('reports.print.data');

    // Public brand/domain identity, consumed by the SPA and marketing site.
    Route::get('/brand', fn () => ApiResponse::success([
        'name' => config('brand.name'),
        'domain' => config('brand.domain'),
        'tagline' => config('brand.tagline'),
        'urls' => [
            'marketing' => config('brand.app_url'),
            'app' => config('brand.application_url'),
            'api' => config('brand.api_url'),
            'docs' => config('brand.docs_url'),
            'status' => config('brand.status_url'),
        ],
        'support_email' => config('brand.support_email'),
        'features' => config('brand.features'),
    ], 'Brand identity.'))->name('brand');

    // PUBLIC, unauthenticated paid-media service catalog for the anonymous marketing homepage + intake.
    // Serves ONLY platform-scope, active, is_public `request.paid_service` options (no tenant data, fail-closed).
    // Rate-limited; ETag + Cache-Control set inside the controller.
    Route::get('/public/catalog/paid-media-services', [PublicPaidServiceController::class, 'index'])
        ->name('public.catalog.paid-media-services')->middleware('throttle:public-catalogue');

    // Domain route files are included here as the platform grows.
    require __DIR__.'/api/identity.php';
    require __DIR__.'/api/crm.php';
    require __DIR__.'/api/integrations.php';
    require __DIR__.'/api/commerce.php';
    require __DIR__.'/api/workspaces.php';
    require __DIR__.'/api/projects.php';
    require __DIR__.'/api/campaigns.php';
    require __DIR__.'/api/settings.php';
    require __DIR__.'/api/requests.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/agency.php';
    require __DIR__.'/api/influencers.php';
    require __DIR__.'/api/clients.php';
    require __DIR__.'/api/billing.php';
    require __DIR__.'/api/messaging.php';
    require __DIR__.'/api/branding.php';
    require __DIR__.'/api/drive.php';
    require __DIR__.'/api/subscriptions.php';
    require __DIR__.'/api/taxonomy.php';
});
