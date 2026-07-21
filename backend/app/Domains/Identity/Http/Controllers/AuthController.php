<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Actions\RegisterTenantAction;
use App\Domains\Identity\DTOs\RegisterData;
use App\Domains\Identity\Http\Requests\LoginRequest;
use App\Domains\Identity\Http\Requests\RegisterRequest;
use App\Domains\Identity\Resources\UserResource;
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
    public function register(RegisterRequest $request, RegisterTenantAction $action): JsonResponse
    {
        $user = $action->execute(RegisterData::fromArray($request->validated()));

        // Establish the SPA session (fires the Login event → audited).
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            'Account created successfully.',
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

        Auth::guard('web')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return ApiResponse::success(
            ['user' => new UserResource($user)],
            'Signed in successfully.',
        );
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

        $token = $user->createToken((string) $request->input('device_name', 'api'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
            'Token issued successfully.',
        );
    }
}
