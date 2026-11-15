<?php

declare(strict_types=1);

namespace App\Domains\Funding\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Funding\Services\RenewNextMonth;
use App\Domains\Funding\Services\WorkspaceFxDefault;
use App\Domains\Tenancy\Context\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BUDGET-FX-DEFAULT-001 — the workspace's default USD→SAR rate: read by anyone who reads budgets, set by finance.
 * Saving it re-reads the spend of every budget of the workspace, so a «ناقص سعر صرف» budget resolves at once.
 */
final class FundingRateController extends Controller
{
    public function __construct(private readonly WorkspaceFxDefault $rates) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('funding.view') || $user->hasPermission('funding.manage')
            || $user->hasPermission('budget.view') || $user->hasPermission('campaigns.view')), 403);

        return ApiResponse::success($this->payload($user), 'Default rate.');
    }

    public function update(Request $request, FundingSpendRefresher $refresher, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('funding.manage'), 403);
        $data = $request->validate(['usd_sar' => ['required', 'numeric', 'min:1', 'max:10']]);

        $tenant = $this->tenantId();
        $before = $this->rates->usdSar($tenant);
        $this->rates->set($tenant, round((float) $data['usd_sar'], 6), (string) $user->id);
        $audit->log(action: 'funding.default_rate.updated', entityType: 'workspace_fx_default', entityId: null,
            before: ['usd_sar' => $before], after: ['usd_sar' => $this->rates->usdSar($tenant)]);

        ProjectFunding::query()->get()->each(static fn (ProjectFunding $f): int => $refresher->refresh($f));

        return ApiResponse::success($this->payload($user), 'Default rate saved.');
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $tenant = $this->tenantId();

        return [
            'usd_sar' => $this->rates->usdSar($tenant),
            'custom' => $this->rates->isCustom($tenant),
            'peg' => 3.75,
            'can_manage' => $user->hasPermission('funding.manage'),
        ];
    }

    private function tenantId(): string
    {
        return (string) app(TenantContext::class)->tenantId();
    }

    /** GET funding/renew-next-month — what «جدّد كل الميزانيات للشهر القادم» would copy, with amounts to confirm. Writes nothing. */
    public function renewPreview(Request $request, RenewNextMonth $renew): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('funding.manage'), 403);

        return ApiResponse::success($renew->preview($user), 'Renewal preview.');
    }

    /** POST funding/renew-next-month — creates only what was confirmed, with the amounts confirmed. */
    public function renewApply(Request $request, RenewNextMonth $renew, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->hasPermission('funding.manage'), 403);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'budgets' => ['present', 'array', 'max:500'],
            'budgets.*.project_id' => ['required', 'uuid'],
            'budgets.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'limits' => ['present', 'array', 'max:500'],
            'limits.*.id' => ['required', 'uuid'],
            'limits.*.amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
        ]);

        $made = $renew->apply($user, $data['budgets'], $data['limits']);
        $audit->log(action: 'funding.renewed_next_month', entityType: 'workspace', entityId: null, before: null, after: $made);

        return ApiResponse::success($made, 'Renewed.', status: 201);
    }
}
