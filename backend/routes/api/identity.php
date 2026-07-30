<?php

declare(strict_types=1);

use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\EmailVerificationController;
use App\Domains\Identity\Http\Controllers\InvitationController;
use App\Domains\Identity\Http\Controllers\MeController;
use App\Domains\Identity\Http\Controllers\OnboardingController;
use App\Domains\Identity\Http\Controllers\UserController;
use App\Domains\Tenancy\Http\Controllers\MembershipController;
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

    // Email verification — verify is public (the link carries the token); resend is authenticated.
    Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->name('email.verify')
        ->middleware('throttle:otp-check');
    Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->name('email.resend')
        ->middleware(['auth:sanctum', 'throttle:6,1']);

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // ADR 0002 — the portal/workspace switcher. `index` answers "where may I go?"; `switch`
        // changes the active membership. Both derive everything from the authenticated user, so
        // neither can be used to enumerate or reach another person's workspaces.
        Route::get('/memberships', [MembershipController::class, 'index'])->name('memberships.index');
        Route::post('/memberships/switch', [MembershipController::class, 'switch'])->name('memberships.switch')
            ->middleware('throttle:30,1');
    });
});

// Workspace invitations — public preview/accept + authorized invite/list.
Route::prefix('invitations')->name('invitations.')->group(function (): void {
    Route::get('/{token}', [InvitationController::class, 'preview'])->name('preview')->middleware('throttle:30,1');
    Route::post('/accept', [InvitationController::class, 'accept'])->name('accept')->middleware('throttle:10,1');
});
Route::middleware(['auth:sanctum', 'tenant'])->prefix('app/team/invitations')->name('app.team.invitations.')->group(function (): void {
    Route::get('/', [InvitationController::class, 'index'])->name('index');
    Route::post('/', [InvitationController::class, 'store'])->name('store')->middleware('throttle:20,1');
});

// Resumable onboarding wizard (authenticated + tenant-scoped).
Route::middleware(['auth:sanctum', 'tenant'])->prefix('onboarding')->name('onboarding.')->group(function (): void {
    Route::get('/state', [OnboardingController::class, 'state'])->name('state');
    Route::post('/account-type', [OnboardingController::class, 'accountType'])->name('account-type');
    Route::post('/service', [OnboardingController::class, 'service'])->name('service');
    Route::post('/workspace', [OnboardingController::class, 'workspace'])->name('workspace');
    Route::post('/first-client', [OnboardingController::class, 'firstClient'])->name('first-client');
    Route::post('/first-project', [OnboardingController::class, 'firstProject'])->name('first-project');
    Route::post('/complete', [OnboardingController::class, 'complete'])->name('complete');
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
