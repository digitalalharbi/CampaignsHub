<?php

declare(strict_types=1);

namespace App\Domains\Funding\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Funding\Services\RenewNextMonth;
use App\Domains\Metrics\Fx\FxEngine;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * BUDGET-FX-DEFAULT-001 superseded by FX-ENGINE-001 (Owner order): every exchange rate is managed from
 * /admin/settings/currency-rates. A workspace reads the platform USD→SAR rate here (the SAR peg, a pegged platform row)
 * and can no longer override it — the PUT answers 410 and points at the platform page. The old per-workspace rows stay
 * in `workspace_fx_defaults`, unread (additive only).
 */
final class FundingRateController extends Controller
{
    public const RATES_PAGE = '/admin/settings/currency-rates';

    public function show(Request $request, FxEngine $fx): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && ($user->hasPermission('funding.view') || $user->hasPermission('funding.manage')
            || $user->hasPermission('budget.view') || $user->hasPermission('campaigns.view')), 403);

        $c = $fx->convert(1, 'USD', 'SAR', Carbon::today());

        return ApiResponse::success([
            'usd_sar' => $c->converted === null ? null : round($c->converted, 6),
            'custom' => false,
            'peg' => 3.75,
            'can_manage' => false,
            'managed_by' => 'platform',
            'rates_page' => self::RATES_PAGE,
            'kind' => $c->kind,
            'source' => $c->source,
            'rate_date' => $c->rateDate,
            'stale' => $c->stale,
            'withheld' => $c->withheld,
        ], 'Platform rate.');
    }

    public function update(): JsonResponse
    {
        return ApiResponse::error(
            'Exchange rates are managed by the platform at '.self::RATES_PAGE.'; a workspace no longer sets its own.',
            errors: ['usd_sar' => ['Managed from the platform rates page.']],
            status: 410,
        );
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
