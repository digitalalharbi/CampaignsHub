<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Billing\Models\Invoice;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The platform owner's overview (ADMIN-001).
 *
 * Counts ACROSS tenants, which is the one thing no other surface in the product is allowed to do —
 * so it enters platform scope explicitly rather than relying on the caller happening to have none.
 * A count that silently returned one tenant's figures would be worse than no console: the owner
 * would read "3 tenants" and believe it.
 *
 * Everything here is a count or a status. No tenant's campaign data, client names or figures appear:
 * owning the platform is not a reason to read a customer's numbers, and doing so from a dashboard
 * would leave no audit trail that it happened.
 */
final class PlatformOverviewController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** GET /api/v1/admin/overview */
    public function __invoke(): JsonResponse
    {
        // Explicit: these queries MUST cross tenants, and saying so is safer than assuming the
        // request arrived unscoped.
        $this->tenants->enterPlatformScope();

        $tenants = Tenant::query()->get(['id', 'status', 'account_type', 'subscription_plan', 'created_at']);

        return ApiResponse::success([
            'tenants' => [
                'total' => $tenants->count(),
                'active' => $tenants->where('status', 'active')->count(),
                'suspended' => $tenants->where('status', 'suspended')->count(),
                'by_account_type' => $tenants->groupBy(fn (Tenant $t) => $t->account_type ?? 'unset')
                    ->map->count()->all(),
                'by_plan' => $tenants->groupBy(fn (Tenant $t) => $t->subscription_plan ?? 'none')
                    ->map->count()->all(),
            ],
            'people' => [
                'users' => User::query()->count(),
                'platform_admins' => User::query()->where('is_platform_admin', true)->count(),
                'memberships' => Membership::query()->count(),
                // Users who belong to no workspace at all. Not an error on its own — a platform
                // admin is one — but a growing number means a grant path is dropping people.
                'without_membership' => User::query()->where('is_platform_admin', false)
                    ->whereDoesntHave('memberships')->count(),
            ],
            'workload' => [
                'client_workspaces' => ClientWorkspace::query()->count(),
                'open_requests' => ExternalRequest::query()
                    ->whereHas('status', fn ($q) => $q->where('is_terminal', false))->count(),
                'unpaid_invoices' => Invoice::query()->whereNotIn('status', ['paid', 'cancelled'])->count(),
            ],
        ], 'Platform overview.');
    }
}
