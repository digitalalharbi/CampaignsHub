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
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterTenantAction $action): JsonResponse
    {
        $user = $action->execute(RegisterData::fromArray($request->validated()));

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
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

        // Fire the Login event so the audit listener records it.
        event(new Login('sanctum', $user, false));

        $token = $user->createToken($request->input('device_name', 'web'))->plainTextToken;

        return ApiResponse::success(
            ['user' => new UserResource($user), 'token' => $token],
            'Signed in successfully.',
        );
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ['user' => new UserResource($request->user())],
            'Current user.',
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return ApiResponse::success(null, 'Signed out successfully.');
    }
}
