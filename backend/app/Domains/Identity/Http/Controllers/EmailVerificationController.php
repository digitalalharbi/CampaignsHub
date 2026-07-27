<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Identity\Resources\UserResource;
use App\Domains\Identity\Services\EmailVerificationService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff email verification: verify a link token (public) and resend (authenticated). */
final class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $service) {}

    /** POST /auth/email/verify — verify a link token. Public (the link carries the token). */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $user = $this->service->verify($data['token']);

        return ApiResponse::success(['user' => new UserResource($user->load('roles', 'tenant'))], 'Email verified.');
    }

    /** POST /auth/email/resend — re-issue a verification link for the current user. */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->email_verified_at !== null) {
            return ApiResponse::success(['already_verified' => true], 'Email already verified.');
        }
        $sent = $this->service->send($user);

        return ApiResponse::success(['email_verification' => $sent], 'Verification link re-issued.');
    }
}
