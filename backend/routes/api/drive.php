<?php

declare(strict_types=1);

use App\Domains\Drive\Http\Controllers\DriveController;
use Illuminate\Support\Facades\Route;

// Tenant-scoped Drive content-linking: folder links, file-metadata browse, attach. Permission-gated inside the
// controller (drive.view / drive.manage); tenant isolation is enforced by the models' global scope.
//
// NOTE: this file is intentionally NOT wired into routes/api.php here — the API orchestrator includes it.
Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('drive/links', [DriveController::class, 'links'])->name('drive.links.index');
    Route::post('drive/links', [DriveController::class, 'linkFolder'])->name('drive.links.store');
    Route::get('drive/links/{link}/files', [DriveController::class, 'files'])->name('drive.links.files');
    Route::delete('drive/links/{link}', [DriveController::class, 'unlink'])->name('drive.links.destroy');

    Route::post('drive/files/{file}/attach', [DriveController::class, 'attachFile'])->name('drive.files.attach');
});
