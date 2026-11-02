<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\TenantLimitOverride;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * SUPERADMIN-LIMITS-001 — one workspace's plan limits, overridden from the console.
 *
 * Each metric shows what the plan allows (trial-aware), what this workspace uses, and its own cap if one was set. A cap can be a
 * number, unlimited, or removed (back to the plan), optionally until a date. It is read by the SAME SubscriptionService path the
 * `EnsureWithinPlanLimit` middleware and the ad-account selection enforce, so it is a real limit, not a label. The plan, its price
 * and its currency are never touched. Every change needs a reason and lands in that workspace's audit trail.
 */
final class PlatformLimitController extends Controller
{
    public const METRICS = ['projects', 'clients', 'team_members', 'ad_accounts', 'connections', 'reports_per_month'];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/admin/tenants/{tenant}/limits */
    public function index(string $tenant): JsonResponse
    {
        $this->tenants->enterPlatformScope();

        return ApiResponse::success(['limits' => $this->describe(Tenant::query()->findOrFail($tenant))], 'Limits.');
    }

    /** PUT /api/v1/admin/tenants/{tenant}/limits/{metric} — `mode` cap (with `limit`), unlimited, or inherit. */
    public function set(Request $request, string $tenant, string $metric): JsonResponse
    {
        abort_unless(in_array($metric, self::METRICS, true), 404);
        $data = $request->validate([
            'mode' => ['required', 'in:cap,unlimited,inherit'],
            'limit' => ['required_if:mode,cap', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $this->tenants->enterPlatformScope();
        $model = Tenant::query()->findOrFail($tenant);
        $before = $this->subscriptions->override($model, $metric);

        if ($data['mode'] === 'inherit') {
            TenantLimitOverride::query()->where('tenant_id', $model->getKey())->where('metric', $metric)->delete();
        } else {
            TenantLimitOverride::query()->updateOrCreate(
                ['tenant_id' => $model->getKey(), 'metric' => $metric],
                [
                    'limit_value' => $data['mode'] === 'cap' ? (int) $data['limit'] : null,
                    'reason' => $data['reason'],
                    'set_by' => $request->user()?->getKey(),
                    'expires_at' => isset($data['expires_at']) ? Carbon::parse((string) $data['expires_at']) : null,
                ],
            );
        }

        $this->audit->log(
            action: 'platform.tenant.limit_set',
            entityType: 'plan_limit',
            entityId: $metric,
            before: ['overridden' => $before[0], 'limit' => $before[1]],
            after: ['mode' => $data['mode'], 'limit' => $data['mode'] === 'cap' ? (int) $data['limit'] : null, 'expires_at' => $data['expires_at'] ?? null],
            reason: $data['reason'],
            userId: $request->user()?->id,
            tenantId: (string) $model->getKey(),
        );

        return ApiResponse::success(['limits' => $this->describe($model)], 'Saved.');
    }

    /** @return list<array<string, mixed>> */
    private function describe(Tenant $tenant): array
    {
        $plan = $this->subscriptions->currentPlan($tenant);
        $trialing = (bool) $this->subscriptions->subscriptionFor($tenant)?->isTrialing();
        $rows = TenantLimitOverride::query()->where('tenant_id', $tenant->getKey())->get()->keyBy('metric');

        return array_map(function (string $metric) use ($tenant, $plan, $trialing, $rows) {
            [$overridden, $cap] = $this->subscriptions->override($tenant, $metric);
            $row = $rows->get($metric);

            return [
                'metric' => $metric,
                'plan_limit' => $plan === null ? null : ($trialing ? $plan->trialLimitFor($metric) : $plan->limitFor($metric)),
                'override' => $overridden ? ['limit' => $cap, 'expires_at' => $row?->expires_at?->toIso8601String(), 'reason' => $row?->reason] : null,
                // An override whose date passed is shown as lapsed, not silently gone.
                'lapsed' => $row !== null && ! $overridden,
                'effective_limit' => $this->subscriptions->effectiveLimit($tenant, $metric),
                'usage' => $this->subscriptions->usage($tenant, $metric),
            ];
        }, self::METRICS);
    }
}
