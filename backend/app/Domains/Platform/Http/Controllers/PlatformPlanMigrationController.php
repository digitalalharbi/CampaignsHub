<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Services\PlanCatalogue;
use App\Domains\Subscriptions\Services\PlanMigration;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * PRICING-MIGRATION-001 — moving launch-catalogue subscribers onto the current catalogue, from the console.
 *
 * - **Preview first.** Every workspace on a withdrawn edition is listed with its usage against today's caps and the new plan's,
 *   and both prices in their own currencies. Reading changes nothing.
 * - **Never silent.** A move needs a reason and the workspace's slug typed back (a bulk move: «MOVE n»). It is audited per
 *   workspace and the customer is told through the scheduled-change notice.
 * - **Never mid-term, never a charge.** A move is booked for the end of the period already paid for; no proration, credit or
 *   payment is opened. Whether a renewal is then collected is the lifecycle's switch (SUBSCRIPTION_LIFECYCLE_ENABLED), not this.
 * - **Only onto a plan on sale that holds what the workspace uses.** A workspace that outgrows every plan is reported, not moved.
 */
final class PlatformPlanMigrationController extends Controller
{
    public function __construct(
        private readonly PlanMigration $migration,
        private readonly PlanCatalogue $catalogue,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly SubscriptionService $subscriptions,
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /api/v1/admin/plan-migration */
    public function index(): JsonResponse
    {
        $this->tenants->enterPlatformScope();
        $subscriptions = $this->migration->candidates();
        $workspaces = Tenant::query()->whereIn('id', $subscriptions->pluck('tenant_id'))->get()->keyBy(fn (Tenant $t) => (string) $t->getKey());

        $rows = $subscriptions
            ->filter(fn (Subscription $s) => $workspaces->has((string) $s->tenant_id))
            ->map(fn (Subscription $s) => $this->migration->describe($s, $workspaces[(string) $s->tenant_id]))
            ->values();

        return ApiResponse::success([
            'workspaces' => $rows,
            'catalogue' => [
                'currency' => $this->catalogue->currency(),
                'plans' => $this->catalogue->offered()->map(fn ($p) => ['code' => $p->code, 'name' => $p->name])->values(),
            ],
        ], 'Plan migration preview.');
    }

    /** GET /api/v1/admin/tenants/{tenant}/plan-migration?plan_code=&billing_interval= — one workspace against a chosen plan. */
    public function show(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['plan_code' => ['required', 'string', 'max:64'], 'billing_interval' => ['nullable', 'in:monthly,annual']]);
        $this->tenants->enterPlatformScope();
        [$model, $subscription] = $this->resolve($tenant);
        $plan = $this->catalogue->byCode($data['plan_code']);
        abort_if($plan === null || ! $this->catalogue->isOffered($plan->code), 422, __('billing.plan_not_available'));

        return ApiResponse::success([
            'workspace' => $this->migration->describe($subscription, $model),
            'target' => $this->migration->against($model, $plan, $data['billing_interval'] ?? (string) $subscription->billing_interval),
        ], 'Migration preview.');
    }

    /** POST /api/v1/admin/tenants/{tenant}/plan-migration */
    public function store(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['required', 'string', 'max:64'],
            'billing_interval' => ['nullable', 'in:monthly,annual'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm' => ['required', 'string', 'max:120'],
        ]);
        $this->tenants->enterPlatformScope();
        [$model, $subscription] = $this->resolve($tenant);

        if (trim($data['confirm']) !== (string) $model->slug) {
            throw ValidationException::withMessages(['confirm' => __('billing.migration_confirm_mismatch')]);
        }

        $error = $this->schedule($request, $model, $subscription, $data['plan_code'], $data['billing_interval'] ?? null, $data['reason']);
        if ($error !== null) {
            return ApiResponse::error($error, status: 422);
        }

        return ApiResponse::success(['workspace' => $this->migration->describe($subscription->refresh(), $model)], 'Move scheduled.', status: 201);
    }

    /** DELETE /api/v1/admin/tenants/{tenant}/plan-migration — withdraw a scheduled move. */
    public function destroy(Request $request, string $tenant): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->tenants->enterPlatformScope();
        [$model, $subscription] = $this->resolve($tenant);
        $before = $subscription->scheduledPlan?->code;

        $this->lifecycle->cancelScheduledChange($subscription, $data['reason']);
        $this->audit->log(
            action: 'platform.plan_migration.withdrawn',
            entityType: Subscription::class,
            entityId: (string) $subscription->getKey(),
            before: ['scheduled_plan' => $before],
            reason: $data['reason'],
            userId: $request->user()?->id,
            tenantId: (string) $model->getKey(),
        );

        return ApiResponse::success(['workspace' => $this->migration->describe($subscription->refresh(), $model)], 'Move withdrawn.');
    }

    /** POST /api/v1/admin/plan-migration/bulk — each listed workspace onto ITS suggestion; the ones that do not fit are reported. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_ids' => ['required', 'array', 'min:1', 'max:200'],
            'tenant_ids.*' => ['required', 'string', 'distinct'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirm' => ['required', 'string', 'max:40'],
        ]);
        if (trim($data['confirm']) !== 'MOVE '.count($data['tenant_ids'])) {
            throw ValidationException::withMessages(['confirm' => __('billing.migration_bulk_confirm_mismatch', ['n' => count($data['tenant_ids'])])]);
        }
        $this->tenants->enterPlatformScope();

        $scheduled = [];
        $skipped = [];
        foreach ($data['tenant_ids'] as $id) {
            $model = Tenant::query()->find($id);
            $subscription = $model === null ? null : $this->subscriptions->subscriptionFor($model);
            if ($model === null || $subscription === null) {
                $skipped[] = ['tenant_id' => $id, 'reason' => 'No subscription.'];

                continue;
            }
            $suggested = $this->migration->suggest($model);
            if ($suggested === null) {
                $skipped[] = ['tenant_id' => $id, 'reason' => 'No plan on sale holds what this workspace uses.'];

                continue;
            }
            $error = $this->schedule($request, $model, $subscription, $suggested->code, null, $data['reason']);
            $error === null ? $scheduled[] = $id : $skipped[] = ['tenant_id' => $id, 'reason' => $error];
        }

        return ApiResponse::success(['scheduled' => $scheduled, 'skipped' => $skipped], 'Bulk move processed.');
    }

    /** Schedule one move, or say why not. */
    private function schedule(Request $request, Tenant $tenant, Subscription $subscription, string $code, ?string $interval, string $reason): ?string
    {
        $plan = $this->catalogue->byCode($code);
        if ($plan === null || ! $this->catalogue->isOffered($code)) {
            return __('billing.plan_not_available');
        }
        if (! $this->migration->candidates()->contains(fn (Subscription $s) => $s->is($subscription))) {
            return 'This workspace is not on a withdrawn catalogue; change its plan from the workspace instead.';
        }
        $interval ??= (string) $subscription->billing_interval;
        $fit = $this->migration->against($tenant, $plan, $interval);
        if (! $fit['fits']) {
            return 'The workspace uses more than that plan holds: '.collect($fit['metrics'])->reject(fn ($m) => $m['fits'])->pluck('metric')->implode(', ').'.';
        }

        $before = ['plan' => $subscription->plan?->code, 'amount' => (string) $subscription->unit_amount, 'currency' => $subscription->currency, 'interval' => $subscription->billing_interval];
        try {
            DB::transaction(function () use ($subscription, $plan, $interval, $reason, $request, $tenant, $before, $fit) {
                $this->lifecycle->scheduleMigration($subscription, $plan, $interval);
                $this->audit->log(
                    action: 'platform.plan_migration.scheduled',
                    entityType: Subscription::class,
                    entityId: (string) $subscription->getKey(),
                    before: $before,
                    after: ['plan' => $plan->code, 'amount' => $fit['amount'], 'currency' => $fit['currency'], 'interval' => $interval,
                        'effective_at' => $subscription->current_period_end?->toIso8601String()],
                    reason: $reason,
                    userId: $request->user()?->id,
                    tenantId: (string) $tenant->getKey(),
                );
            });
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** @return array{0: Tenant, 1: Subscription} */
    private function resolve(string $tenant): array
    {
        $model = Tenant::query()->findOrFail($tenant);
        $subscription = $this->subscriptions->subscriptionFor($model);
        abort_if($subscription === null, 404, 'This workspace has no subscription.');

        return [$model, $subscription];
    }
}
