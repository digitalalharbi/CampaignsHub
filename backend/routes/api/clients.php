<?php

declare(strict_types=1);

use App\Domains\ClientWorkspaces\Http\Controllers\Internal\ClientsController;
use Illuminate\Support\Facades\Route;

/*
| Client portfolio + command center (auth + tenant; permission enforced in the controller).
*/
Route::middleware(['auth:sanctum', 'tenant'])->prefix('app/clients')->name('app.clients.')->group(function (): void {
    Route::get('/', [ClientsController::class, 'index'])->name('index');
    Route::get('/{client}', [ClientsController::class, 'show'])->name('show');
});
