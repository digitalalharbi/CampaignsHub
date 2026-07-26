<?php

declare(strict_types=1);

namespace App\Domains\Settings\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Settings\Services\Totp;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Account security: password change, real TOTP two-factor, sign-in history (from the audit trail — the
 * source of truth we already record), and the organisation session/security policy. We never fabricate
 * a live-session list; "recent activity" is derived from actual audited login events.
 */
final class SecurityController extends Controller
{
    public function __construct(private readonly Totp $totp) {}

    /** Sign-in activity + distinct recent devices, from real audit events for the current user. */
    public function activity(Request $request): JsonResponse
    {
        $events = AuditLog::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('action', ['user.login', 'user.logout'])
            ->latest()->limit(50)
            ->get(['action', 'ip_address', 'user_agent', 'created_at']);

        $devices = $events->where('action', 'user.login')
            ->unique(fn ($e) => $e->ip_address.'|'.$e->user_agent)
            ->take(10)
            ->map(fn ($e) => ['ip_address' => $e->ip_address, 'user_agent' => $e->user_agent, 'last_seen' => $e->created_at?->toIso8601String()])
            ->values();

        return ApiResponse::success([
            'history' => $events->map(fn ($e) => [
                'action' => $e->action, 'ip_address' => $e->ip_address,
                'user_agent' => $e->user_agent, 'at' => $e->created_at?->toIso8601String(),
            ])->values(),
            'devices' => $devices,
            'two_factor_enabled' => (bool) $request->user()->two_factor_enabled,
        ], 'Security activity retrieved.');
    }

    public function changePassword(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Current password is incorrect.');

        $request->user()->forceFill(['password' => Hash::make($data['password'])])->save();
        $audit->log(action: 'security.password_changed', entityType: 'user', entityId: $request->user()->uuid);

        return ApiResponse::success(null, 'Password updated.');
    }

    /** Begin 2FA setup: generate a secret (stored, not yet enabled) + otpauth URI for the authenticator. */
    public function mfaSetup(Request $request): JsonResponse
    {
        $secret = $this->totp->generateSecret();
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_enabled' => false])->save();

        return ApiResponse::success([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->uri($secret, $request->user()->email, 'CampaignsHub'),
        ], 'Two-factor setup started.');
    }

    public function mfaConfirm(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $secret = $request->user()->two_factor_secret;
        abort_if($secret === null, 422, 'Start two-factor setup first.');
        abort_unless($this->totp->verify($secret, $data['code']), 422, 'Invalid verification code.');

        $request->user()->forceFill(['two_factor_enabled' => true])->save();
        $audit->log(action: 'security.mfa_enabled', entityType: 'user', entityId: $request->user()->uuid);

        return ApiResponse::success(['two_factor_enabled' => true], 'Two-factor enabled.');
    }

    public function mfaDisable(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        abort_unless(Hash::check($data['password'], $request->user()->password), 422, 'Password is incorrect.');

        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_enabled' => false])->save();
        $audit->log(action: 'security.mfa_disabled', entityType: 'user', entityId: $request->user()->uuid);

        return ApiResponse::success(['two_factor_enabled' => false], 'Two-factor disabled.');
    }

    /** Organisation-wide security policy (session timeout, new-device alerts). settings.manage. */
    public function showPolicy(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $policy = array_merge(
            ['session_timeout_minutes' => 120, 'alert_new_device' => true, 'alert_failed_logins' => true],
            (array) (($tenant->settings ?? [])['security'] ?? []),
        );

        return ApiResponse::success(['policy' => $policy], 'Security policy retrieved.');
    }

    public function updatePolicy(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->hasPermission('settings.manage'), 403);
        $tenant = $this->tenant();

        $data = $request->validate([
            'policy.session_timeout_minutes' => ['required', 'integer', 'between:5,10080'],
            'policy.alert_new_device' => ['boolean'],
            'policy.alert_failed_logins' => ['boolean'],
        ]);

        $settings = (array) ($tenant->settings ?? []);
        $before = $settings['security'] ?? null;
        $settings['security'] = $data['policy'];
        $tenant->forceFill(['settings' => $settings])->save();

        $audit->log(action: 'settings.security.updated', entityType: 'tenant', entityId: (string) $tenant->id, before: $before, after: $data['policy']);

        return $this->showPolicy($request);
    }

    private function tenant(): Tenant
    {
        $id = app(TenantContext::class)->tenantId();
        abort_if($id === null, 403, 'No tenant context.');

        return Tenant::query()->findOrFail($id);
    }
}
