<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Identity\Services\AuthCodeSwitches;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's switches for one-time codes at sign-in and recovery (AUTH-CODE-SWITCHES-001).
 *
 * Behind the `platform` middleware, so a tenant administrator cannot reach it. Password, Google and the reset link are not here.
 */
final class PlatformAuthCodeSwitchesController extends Controller
{
    public function __construct(
        private readonly AuthCodeSwitches $switches,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/admin/auth-code-switches */
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->switches->overview(), 'Sign-in and recovery code switches.');
    }

    /** PUT /api/v1/admin/auth-code-switches */
    public function update(Request $request): JsonResponse
    {
        $rules = array_fill_keys(AuthCodeSwitches::SWITCHES, ['required', 'boolean']);
        $data = $request->validate($rules + [
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'confirm_risk' => ['sometimes', 'boolean'],
        ]);

        $current = $this->switches->current();
        $turningOff = array_filter(AuthCodeSwitches::SWITCHES, fn (string $switch): bool => $current[$switch] && ! $data[$switch]);

        // Confirmed by the person, on the server: a form that forgot to ask fails here rather than closing a door.
        abort_if($turningOff !== [] && ! ($data['confirm_risk'] ?? false), 422, 'Confirm the risk of turning this off.');

        $this->switches->update(
            changes: array_intersect_key($data, $rules),
            expectedVersion: (int) $data['expected_version'],
            reason: (string) $data['reason'],
            actor: $request->user(),
            audit: $this->audit,
        );

        return ApiResponse::success($this->switches->overview(), 'Sign-in and recovery code switches updated.');
    }
}
