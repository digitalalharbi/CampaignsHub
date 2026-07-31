<?php

declare(strict_types=1);

use App\Domains\Messaging\Http\Controllers\MessagingController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped messaging: client ⇄ internal-team threads. Permission-gated inside the controller
// (messaging.view / messaging.manage); tenant isolation is enforced by the models' global scope.
Route::middleware(['auth:sanctum', 'tenant', 'portal:agency,influencers'])->group(function (): void {
    Route::get('messaging/threads', [MessagingController::class, 'threads'])->name('messaging.threads.index');
    Route::post('messaging/threads', [MessagingController::class, 'storeThread'])->name('messaging.threads.store');
    Route::get('messaging/threads/{messageThread}', [MessagingController::class, 'show'])->name('messaging.threads.show');
    Route::post('messaging/threads/{messageThread}/messages', [MessagingController::class, 'postMessage'])->name('messaging.threads.messages.store');
    Route::post('messaging/threads/{messageThread}/read', [MessagingController::class, 'markRead'])->name('messaging.threads.read');
});
