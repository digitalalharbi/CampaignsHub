<?php

declare(strict_types=1);

use App\Domains\Delivery\Http\Controllers\DeliveryController;
use Illuminate\Support\Facades\Route;

// WD-PACK-001 / WD-EVIDENCE-001 — the agency's weekly delivery. Permission-gated inside the controller
// (reports.view / reports.approve) and scoped to the clients the operator can reach.
Route::middleware(['auth:sanctum', 'tenant', 'portal:agency'])->prefix('delivery')->name('delivery.')->group(function (): void {
    Route::get('overview', [DeliveryController::class, 'overview'])->name('overview');
    Route::get('settings', [DeliveryController::class, 'settings'])->name('settings.show');
    Route::put('settings', [DeliveryController::class, 'updateSettings'])->name('settings.update');
    Route::get('packs', [DeliveryController::class, 'index'])->name('packs.index');
    Route::post('packs', [DeliveryController::class, 'prepare'])->name('packs.prepare');
    Route::get('packs/{pack}', [DeliveryController::class, 'show'])->name('packs.show');
    Route::put('packs/{pack}/components', [DeliveryController::class, 'components'])->name('packs.components');
    Route::post('packs/{pack}/invoice', [DeliveryController::class, 'invoice'])->name('packs.invoice');
    Route::post('packs/{pack}/approve', [DeliveryController::class, 'approve'])->name('packs.approve');
    Route::post('packs/{pack}/sent', [DeliveryController::class, 'sent'])->name('packs.sent');
    Route::get('packs/{pack}/whatsapp', [DeliveryController::class, 'whatsapp'])->name('packs.whatsapp');
    Route::patch('packs/{pack}/recommendations/{annotation}', [DeliveryController::class, 'editRecommendation'])->name('packs.recommendations.edit');

    Route::put('packs/{pack}/notes/order', [DeliveryController::class, 'reorderNotes'])->name('packs.notes.order');
    Route::post('packs/{pack}/notes', [DeliveryController::class, 'addNote'])->name('packs.notes.add');
    Route::patch('packs/{pack}/notes/{note}', [DeliveryController::class, 'updateNote'])->name('packs.notes.update');
    Route::delete('packs/{pack}/notes/{note}', [DeliveryController::class, 'deleteNote'])->name('packs.notes.delete');
    Route::post('evidence', [DeliveryController::class, 'storeEvidence'])->name('evidence.store');
    Route::delete('evidence/{evidence}', [DeliveryController::class, 'deleteEvidence'])->name('evidence.delete');
    Route::get('evidence/{evidence}/file', [DeliveryController::class, 'evidenceFile'])->name('evidence.file');
});
