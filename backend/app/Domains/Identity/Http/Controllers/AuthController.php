<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RegisterTenantAction;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Http\Requests\RegisterRequest;
use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\EmailVerificationService;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SPA cookie-session authentication (Sanctum stateful). See ADR 0001.
 * Personal Access Tokens are issued only via {@see issueToken()} for non-browser clients.
 */
final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterTenantAction $action, EmailVerificationService $verification): JsonResponse
    {
        $user = $action->execute(RegisterData::fromArray($request->validated()));

        // Issue an email-verification challenge (delivery is honest — awaiting provider credentials).
        $sent = $verification->send($user);

        // Establish the SPA session (fires the Login event → audited).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return ApiResponse::success(
            ['user' => new UserResource($user), 'email_verification' => $sent],
            'Account created. Please verify your email to continue.',
            status: 201,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }
        $this->assertActive($user);

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            'Signed in successfully.',
        );
    }

    /** A suspended/disabled account (or suspended workspace) can never sign in or mint a token. Generic message. */
    private function assertActive(User $user): void
    {
        $suspended = $user->disabled_at !== null
            || ($user->tenant_id !== null && in_array(Tenant::whereKey($user->tenant_id)->value('status'), ['suspended', 'inactive'], true));
        abort_if($suspended, 403, 'Your account is not available. Please contact support.');
    }

    /** Current authenticated user — used by the SPA to restore its session on load. */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['user' => new UserResource($request->user())],
            'Current user.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return ApiResponse::success(null, 'Signed out successfully.');
    }

    /**
     * Password-reset request. Responds with the SAME generic message whether or not the account
     * exists (no account enumeration). Actual email delivery depends on mail credentials — until a
     * mailer is configured this records the intent in the log and returns success (Awaiting
     * Credentials), so the UI flow is complete end-to-end without leaking real vs. unknown emails.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if (User::where('email', $data['email'])->exists()) {
            // TODO(Awaiting Credentials): once a mailer is configured, dispatch the reset link here.
            logger()->info('Password reset requested', ['email' => $data['email']]);
        }

        return ApiResponse::success(null, 'If an account exists for that email, a reset link has been sent.');
    }

    /**
     * Issue a Personal Access Token for NON-browser API clients (mobile, integrations).
     * Browsers use the cookie session above and never receive a token.
     */
    public function issueToken(LoginRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if ($user === null || ! Hash::check((string) $request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }
        $this->assertActive($user);

        $token = $user->createToken((string) $request->input('device_name', 'api'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
            'Token issued successfully.',
        );
    }
}
