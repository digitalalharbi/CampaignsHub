<?php

declare(strict_types=1);

use App\Domains\Bootstrap\Http\Controllers\BootstrapController;
use Illuminate\Support\Facades\Route;

/*
 * PERF-BOOTSTRAP-001 — what every page load asks first, in one request. Authenticated and tenant-resolved like the endpoints it
 * folds together; each section inside is gated exactly as its own endpoint (see the controller), so this route adds no reach.
 */
Route::middleware(['auth:sanctum', 'tenant'])->get('bootstrap', BootstrapController::class)->name('bootstrap');
