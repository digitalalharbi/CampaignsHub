<?php

declare(strict_types=1);

use App\Domains\Accounts\Http\Controllers\RegistrationController;
use App\Domains\Identity\Http\Controllers\AuthController;
use App\Domains\Identity\Http\Controllers\EmailVerificationController;
use App\Domains\Identity\Http\Controllers\InvitationController;
use App\Domains\Identity\Http\Controllers\MeController;
use App\Domains\Identity\Http\Controllers\OAuthController;
use App\Domains\Identity\Http\Controllers\OnboardingController;
use App\Domains\Identity\Http\Controllers\PhoneSignInController;
use App\Domains\Identity\Http\Controllers\UserController;
use App\Domains\Legal\Http\Controllers\ConsentController;
use App\Domains\Tenancy\Http\Controllers\MembershipController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
| Identity & authentication endpoints (Sanctum).
| Public: register, login. Authenticated: current user, logout.
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    /*
     * SIGNUP-002 — applying is not the same as having an account.
     *
     * `register` no longer creates a tenant, a workspace, a user or a membership. It records an
     * application and issues a verification challenge; everything downstream depends on the
     * registration policy for the chosen plan. The endpoints below it are how an applicant, who by
     * definition has no session, answers those challenges and reads where they stand.
     *
     * All public, all rate limited, and none of them able to advance an application on its own —
     * each records a fact and lets `AdvanceRegistration` decide what follows.
     */
    Route::post('/register', [RegistrationController::class, 'store'])->name('register')
        ->middleware('throttle:registration');
    Route::prefix('registration')->name('registration.')->group(function (): void {
        Route::post('/verify-email', [RegistrationController::class, 'verifyEmail'])->name('verify-email')
            ->middleware('throttle:otp-check');
        Route::get('/{registration}', [RegistrationController::class, 'show'])->name('show')
            ->middleware('throttle:60,1');
        Route::post('/{registration}/verify-mobile', [RegistrationController::class, 'verifyMobile'])
            ->name('verify-mobile')->middleware('throttle:otp-check');
        Route::post('/{registration}/resend', [RegistrationController::class, 'resend'])->name('resend')
            ->middleware('throttle:6,1');
    });

    /*
     * LOGIN-UNIFIED-001. The single sign-in page asks this which form to render for an identifier,
     * so the visitor never picks a portal. Throttled like a sign-in attempt because it takes an
     * identifier and touches the users table — an unthrottled version would be a cheap way to probe
     * addresses even though the answer is deliberately uninformative.
     */
    Route::post('/method', [AuthController::class, 'method'])->name('method')
        ->middleware('throttle:auth-login');

    Route::post('/login', [AuthController::class, 'login'])->name('login')
        ->middleware('throttle:auth-login');

    /*
     * LOGIN-PATHS-001 — the mobile-number path.
     *
     * Its own endpoints rather than the client portal's, because `/client/login/verify` opens a
     * PORTAL session: pointing a platform user's phone at it would have signed them into a space
     * they hold nothing in, and the page would have looked like it worked.
     *
     * `start` is throttled per destination AND per IP (`otp-request`), which is what bounds the cost
     * of answering identically for numbers nobody holds — the property that keeps this from being a
     * directory of who has an account here.
     */
    Route::post('/phone/start', [PhoneSignInController::class, 'start'])->name('phone.start')
        ->middleware('throttle:otp-request');
    Route::post('/phone/verify', [PhoneSignInController::class, 'verify'])->name('phone.verify')
        ->middleware('throttle:otp-check');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password')
        ->middleware('throttle:6,1');
    /*
     * Public, because somebody resetting a password cannot sign in — that is what they are here for.
     * The token in the body is the authorisation. Throttled like an OTP check: it is the one endpoint
     * where guessing has a payoff, and `PasswordResetService` answers every failure identically so a
     * caller learns nothing from the attempt except that it did not work.
     */
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password')
        ->middleware('throttle:otp-check');
    // Personal Access Token issuance for non-browser API clients only.
    Route::post('/tokens', [AuthController::class, 'issueToken'])->name('tokens')
        ->middleware('throttle:6,1');

    /*
     * Social sign-in (LOGIN-004). `providers` is public so the sign-in page can render each one's
     * real state — Live, or Awaiting Credentials — instead of offering a button that cannot work.
     * `start` and `callback` are public too: they ARE the sign-in.
     */
    Route::get('/oauth/providers', [OAuthController::class, 'providers'])->name('oauth.providers');

    /*
     * `start` and `callback` need a SESSION of their own, stated explicitly here.
     *
     * The PKCE verifier, `state` and `nonce` are minted by `start` and read back by `callback`, and
     * the session is where they live — anywhere else and they would have to travel through the
     * browser, which defeats all three. Sanctum's stateful middleware is not enough on its own: it
     * only engages for requests whose Origin matches the SPA, and the callback arrives as a
     * top-level navigation FROM THE PROVIDER with no such header.
     */
    Route::middleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        'throttle:20,1',
    ])->group(function (): void {
        Route::post('/oauth/{provider}/start', [OAuthController::class, 'start'])->name('oauth.start');
        // Apple posts the callback back as a form when scopes are requested; Google uses GET.
        Route::match(['get', 'post'], '/oauth/{provider}/callback', [OAuthController::class, 'callback'])
            ->name('oauth.callback');
    });

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
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency'])->prefix('app/team/invitations')->name('app.team.invitations.')->group(function (): void {
    Route::get('/', [InvitationController::class, 'index'])->name('index');
    Route::post('/', [InvitationController::class, 'store'])->name('store')->middleware('throttle:20,1');
});

// Resumable onboarding wizard (authenticated + tenant-scoped).
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])->prefix('onboarding')->name('onboarding.')->group(function (): void {
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
Route::middleware(['auth:sanctum', 'tenant', 'portal:app,agency,influencers'])
    ->get('users', [UserController::class, 'index'])
    ->name('users.index');

/*
 * LEGAL-003 — the binding documents a signed-in user still owes, and accepting them.
 *
 * Here rather than behind a portal gate because it applies to every signed-in person regardless of
 * which portal they are in: a new terms version has to be answerable by an agency operator, an
 * advertiser and a platform owner alike, and gating it per portal would leave one of them unable to
 * clear the prompt blocking them.
 */
Route::middleware('auth:sanctum')->prefix('legal')->name('legal.')->group(function (): void {
    Route::get('/outstanding', [ConsentController::class, 'outstanding'])->name('outstanding');
    Route::post('/accept', [ConsentController::class, 'accept'])->middleware('throttle:10,1')->name('accept');
});
