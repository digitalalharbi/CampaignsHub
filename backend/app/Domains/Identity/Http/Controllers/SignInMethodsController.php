<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Platform\Services\OperationalReadiness;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-LOGIN-UX-002 — which ways in the sign-in page may offer right now.
 *
 * A way in that cannot work must not be shown. The email code depends on mail, so it is offered unless the mail transport is KNOWN to
 * be failing (its latest real attempt failed — MAIL-HEALTH-001); «unknown» still offers it, because a fresh environment has sent
 * nothing yet and the code step already says honestly when a message was not delivered. Google has its own readiness at
 * `/auth/oauth/providers`. Only a boolean leaves here: no error text, no address, no configuration.
 */
final class SignInMethodsController extends Controller
{
    public function __invoke(OperationalReadiness $readiness): JsonResponse
    {
        return ApiResponse::success([
            'password' => ['available' => true],
            'email_code' => ['available' => $readiness->mailState() !== 'failing'],
        ], 'Sign-in methods.');
    }
}
