<?php

declare(strict_types=1);

use App\Domains\Taxonomy\Http\Controllers\TaxonomyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Taxonomy & Option management (tenant-scoped, permissioned)
|--------------------------------------------------------------------------
| The central engine that replaces per-page hardcoded option arrays. Reads need taxonomies.view; each mutation
| needs its own permission (gated inside the controller). Tenant isolation is enforced by TaxonomyService,
| which resolves options across the tenant global scope but re-asserts "platform ∪ current tenant".
|
| NOTE: This file is intentionally NOT required from routes/api.php — the orchestrator wires it.
*/

/*
 * TAX-ADMIN-001 — the same engine, reached from the platform console.
 *
 * `/admin/settings` → «التصنيفات» mounts this manager, and the platform operator belongs to no tenant:
 * behind the group below the request was refused before it reached the controller, and the tab could
 * only ever show «تعذّر تحميل البيانات» to the one person entitled to edit the shared layer.
 *
 * Nothing about the engine changes. `TaxonomyService` already resolves «platform ∪ current tenant», so
 * with no tenant in context it reads and writes exactly the PLATFORM options — which is the whole of
 * what this console is for. The tenant group below stays: a customer editing their own options is a
 * different, equally legitimate job, and this is one engine serving both layers rather than two.
 */
Route::middleware(['auth:sanctum', 'platform'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('taxonomies', [TaxonomyController::class, 'index'])->name('taxonomies.index');
    Route::get('taxonomies/{key}/options', [TaxonomyController::class, 'options'])->name('taxonomies.options.index');
    Route::post('taxonomies/{key}/options', [TaxonomyController::class, 'storeOption'])->name('taxonomies.options.store');

    Route::patch('options/{id}', [TaxonomyController::class, 'updateOption'])->name('taxonomies.options.update');
    Route::post('options/reorder', [TaxonomyController::class, 'reorder'])->name('taxonomies.options.reorder');
    Route::post('options/{id}/merge', [TaxonomyController::class, 'merge'])->name('taxonomies.options.merge');
    Route::post('options/{id}/reassign', [TaxonomyController::class, 'reassign'])->name('taxonomies.options.reassign');
    Route::post('options/{id}/deactivate', [TaxonomyController::class, 'deactivate'])->name('taxonomies.options.deactivate');
    Route::get('options/{id}/usage', [TaxonomyController::class, 'usage'])->name('taxonomies.options.usage');
});

Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])->group(function (): void {
    Route::get('taxonomies', [TaxonomyController::class, 'index'])->name('taxonomies.index');
    Route::get('taxonomies/{key}/options', [TaxonomyController::class, 'options'])->name('taxonomies.options.index');
    Route::post('taxonomies/{key}/options', [TaxonomyController::class, 'storeOption'])->name('taxonomies.options.store');

    Route::patch('options/{id}', [TaxonomyController::class, 'updateOption'])->name('taxonomies.options.update');
    Route::post('options/reorder', [TaxonomyController::class, 'reorder'])->name('taxonomies.options.reorder');
    Route::post('options/{id}/merge', [TaxonomyController::class, 'merge'])->name('taxonomies.options.merge');
    Route::post('options/{id}/reassign', [TaxonomyController::class, 'reassign'])->name('taxonomies.options.reassign');
    Route::post('options/{id}/deactivate', [TaxonomyController::class, 'deactivate'])->name('taxonomies.options.deactivate');
    Route::get('options/{id}/usage', [TaxonomyController::class, 'usage'])->name('taxonomies.options.usage');
});
