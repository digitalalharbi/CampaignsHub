<?php

declare(strict_types=1);

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

    // Public, token-gated, expiring report download (the shareable secure link).
    Route::get('/reports/download/{token}', ReportDownloadController::class)->name('reports.download');

    // Public secure client report links (token-gated, sanitized, logged).
    Route::get('/reports/shared/{token}', [PublicReportController::class, 'show'])->name('reports.shared.show');
    // LIVEREP-001 — figures recomputed on request, inside the link's own ceiling. Same token, same
    // password gate, same access log; a snapshot link answers this with 409 rather than empty data.
    Route::get('/reports/shared/{token}/live', [PublicReportController::class, 'live'])->name('reports.shared.live');
    Route::get('/reports/shared/{token}/download/{format}', [PublicReportController::class, 'download'])->name('reports.shared.download');

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
        ->name('public.catalog.paid-media-services')->middleware('throttle:60,1');

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
    require __DIR__.'/api/connections.php';
    require __DIR__.'/api/drive.php';
    require __DIR__.'/api/subscriptions.php';
    require __DIR__.'/api/taxonomy.php';
});
