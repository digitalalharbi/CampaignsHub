<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Support\SupportAccess;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * SUPERADMIN-SUPPORT-ACCESS-001 — start and end a support visit from the tenants page.
 *
 * Starting needs a reason and the operator's own password again — no tenant password is ever asked for or used. The visit is
 * bound to THIS browser's session: another device holding the same admin account does not inherit it.
 */
final class PlatformSupportSessionController extends Controller
{
    public function __construct(private readonly SupportAccess $support, private readonly TenantContext $tenants) {}

    /** POST /api/v1/admin/tenants/{tenant}/support-session */
    public function start(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'current_password' => ['required', 'string'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        if (! Hash::check($data['current_password'], (string) $admin->password)) {
            throw ValidationException::withMessages(['current_password' => __('The current password is incorrect.')]);
        }

        abort_unless($request->hasSession(), 409, 'Support access needs a browser session.');

        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);

        $visit = $this->support->start($admin, $model, $data['reason'], $request->ip());
        $request->session()->put(SupportAccess::SESSION_KEY, $visit->id);

        return ApiResponse::success([
            'support_session_id' => $visit->id,
            'tenant' => ['id' => (string) $model->getKey(), 'name' => $model->name],
            'mode' => $visit->mode,
            'expires_at' => $visit->expires_at->toIso8601String(),
            'landing_path' => ($model->account_type === 'agency' ? Portal::Agency : Portal::App)->landingPath(),
        ], 'Support access started.');
    }

    /** DELETE /api/v1/admin/support-session — back to the console. */
    public function end(Request $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $this->support->endFor($admin);
        if ($request->hasSession()) {
            $request->session()->forget(SupportAccess::SESSION_KEY);
        }

        return ApiResponse::success(['ended' => true, 'return_path' => '/admin/tenants'], 'Support access ended.');
    }
}
