<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Accounts\Services\RegistrationVerificationSettings;
use App\Domains\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The platform's signup-verification switches (SIGNUP-VERIFY-POLICY-001).
 *
 * Behind the `platform` middleware, so a tenant administrator cannot reach it. It changes what NEW applications are asked
 * to prove. It cannot activate anyone, waive any payment or approval, or move an application already in the queue.
 */
final class PlatformRegistrationPolicyController extends Controller
{
    public function __construct(
        private readonly RegistrationVerificationSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/admin/registration-verification-policy */
    public function show(): JsonResponse
    {
        return ApiResponse::success($this->settings->overview(), 'Registration verification policy.');
    }

    /** PUT /api/v1/admin/registration-verification-policy */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'mobile_enabled' => ['required', 'boolean'],
            'email_daily_cap' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'sms_daily_cap' => ['present', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'per_application_daily_cap' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            // Entered by somebody who has read the provider's invoice. Null stays «unknown».
            'email_unit_cost' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'sms_unit_cost' => ['present', 'nullable', 'numeric', 'min:0', 'max:1000'],
            'cost_currency' => ['present', 'nullable', 'string', 'size:3', 'required_with:email_unit_cost,sms_unit_cost'],
            'budget_alert_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'unverified_signups_daily_cap' => ['required', 'integer', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'confirm_risk' => ['sometimes', 'boolean'],
        ]);

        $current = $this->settings->current();
        $turningOff = ($current['email_enabled'] && ! $data['email_enabled']) || ($current['mobile_enabled'] && ! $data['mobile_enabled']);

        // Confirmed by the person, on the server: a form that forgot to ask fails here rather than disabling a gate.
        abort_if($turningOff && ! ($data['confirm_risk'] ?? false), 422, 'Confirm the risk of disabling verification.');

        foreach (['email_unit_cost', 'sms_unit_cost'] as $cost) {
            $data[$cost] = $data[$cost] === null ? null : (string) $data[$cost];
        }
        if ($data['cost_currency'] !== null) {
            $data['cost_currency'] = strtoupper((string) $data['cost_currency']);
        }

        $this->settings->update(
            changes: array_diff_key($data, array_flip(['reason', 'expected_version', 'confirm_risk'])),
            expectedVersion: (int) $data['expected_version'],
            reason: (string) $data['reason'],
            actor: $request->user(),
            audit: $this->audit,
        );

        return ApiResponse::success($this->settings->overview(), 'Registration verification policy updated.');
    }
}
