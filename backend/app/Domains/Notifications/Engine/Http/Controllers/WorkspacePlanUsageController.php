<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Http\Controllers;

use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Contracts\UsageCounter;
use App\Domains\Notifications\Engine\Contracts\WorkspaceTypes;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Engine\Usage\MonthlyUsageCounter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The workspace's own view of its notification plan (spec §1): every entitlement key with its mode and limits, and this
 * month's usage counters against them. Read-only; the tenant comes from the membership, never from the request.
 */
final class WorkspacePlanUsageController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly EntitlementGate $gate,
        private readonly UsageCounter $usage,
        private readonly WorkspaceTypes $types,
    ) {}

    public function entitlements(): JsonResponse
    {
        $tenantId = (string) $this->tenants->tenantId();

        return ApiResponse::success([
            'workspace_type' => $this->types->profileFor($tenantId)->toArray(),
            'entitlements' => array_map(fn (EntitlementKey $k): array => $this->gate->state($tenantId, $k)->toArray(), EntitlementKey::cases()),
        ]);
    }

    public function usage(): JsonResponse
    {
        $tenantId = (string) $this->tenants->tenantId();
        $limits = [];
        foreach (array_keys((array) config('notifications_v2.entitlements.limits', [])) as $limitKey) {
            $limits[(string) $limitKey] = $this->gate->limit($tenantId, (string) $limitKey);
        }

        return ApiResponse::success([
            'period' => $this->usage instanceof MonthlyUsageCounter ? $this->usage->period($tenantId) : now()->format('Y-m'),
            'counts' => $this->usage->month($tenantId),
            'limits' => $limits,
        ]);
    }
}
