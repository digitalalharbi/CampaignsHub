<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\MeController;
use App\Domains\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Identity & authentication endpoints (Sanctum).
| Public: register, login. Authenticated: current user, logout.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login')
        ->middleware('throttle:auth-login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password')
        ->middleware('throttle:6,1');
    // Personal Access Token issuance for non-browser API clients only.
    Route::post('/tokens', [AuthController::class, 'issueToken'])->name('tokens')
        ->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

// The signed-in user's own account (self-only; no user id is ever accepted from the client).
Route::middleware(['auth:sanctum', 'tenant'])->prefix('me')->name('me.')->group(function (): void {
    Route::get('/', [MeController::class, 'show'])->name('show');
    Route::patch('/profile', [MeController::class, 'updateProfile'])->name('profile.update');
    Route::get('/sessions', [MeController::class, 'sessions'])->name('sessions');

    // Credential-changing actions are rate limited independently of login.
    Route::patch('/password', [MeController::class, 'updatePassword'])->name('password.update')
        ->middleware('throttle:6,1');
    Route::delete('/sessions/others', [MeController::class, 'logoutOtherSessions'])->name('sessions.others')
        ->middleware('throttle:6,1');
});

// Tenant users (for member/team pickers).
Route::middleware(['auth:sanctum', 'tenant'])
    ->get('users', [UserController::class, 'index'])
    ->name('users.index');
