<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Identity & authentication endpoints (Sanctum).
| Public: register, login. Authenticated: current user, logout.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login')
        ->middleware('throttle:6,1');
    // Personal Access Token issuance for non-browser API clients only.
    Route::post('/tokens', [AuthController::class, 'issueToken'])->name('tokens')
        ->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// Tenant users (for member/team pickers).
Route::middleware(['auth:sanctum', 'tenant'])
    ->get('users', [UserController::class, 'index'])
    ->name('users.index');
