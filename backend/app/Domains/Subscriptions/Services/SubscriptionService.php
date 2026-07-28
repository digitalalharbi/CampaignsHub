<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Models\UsageCounter;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Carbon;

/**
 * The subscription / plan-limit engine. It is honest and fail-open by construction:
 *
 *   - A tenant WITHOUT a subscription defaults to the MOST PERMISSIVE active plan, so nothing regresses when
 *     subscriptions are not yet provisioned (see {@see currentPlan()} and {@see mostPermissivePlan()}).
 *   - withinLimit() compares REAL usage_counters against the plan's cap for a metric. A null/absent cap means
 *     unlimited → always within.
 *   - increment() meters usage as a safe upsert on (tenant, metric, period).
 *
 * Every query is scoped EXPLICITLY to the passed tenant (and drops the tenant global scope) so the result is
 * deterministic regardless of the request-scoped TenantContext — the service can be called for any tenant.
 */
final class SubscriptionService
{
    /**
     * The plan a tenant is effectively on. When the tenant has no subscription (or the subscription points at a
     * missing plan) it defaults to the most permissive active plan so limits never block an unprovisioned tenant.
     */
    public function currentPlan(Tenant $tenant): ?SubscriptionPlan
    {
        $subscription = $this->subscriptionFor($tenant);
        $plan = $subscription?->plan;

        return $plan ?? $this->mostPermissivePlan();
    }

    public function subscriptionFor(Tenant $tenant): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->first();

        return $subscription;
    }

    /**
     * Assign (or move) a tenant onto a plan. Idempotent: one subscription row per tenant is created or updated.
     */
    public function assignPlan(Tenant $tenant, SubscriptionPlan $plan, string $status = 'active', ?Carbon $currentPeriodEnd = null, ?int $seats = null): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['tenant_id' => $tenant->id]);

        $subscription->fill([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'current_period_end' => $currentPeriodEnd ?? $subscription->current_period_end,
            'seats' => $seats ?? $subscription->seats ?? 1,
        ])->save();

        return $subscription->refresh()->load('plan');
    }

    /**
     * Is the tenant still within its plan cap for a metric? A null/absent cap (unlimited) is always within.
     * Fail-open when there is no plan at all (no catalogue seeded yet) — never block on a missing catalogue.
     */
    public function withinLimit(Tenant $tenant, string $metric): bool
    {
        $plan = $this->currentPlan($tenant);
        if ($plan === null) {
            return true; // no catalogue → do not block
        }

        $limit = $plan->limitFor($metric);
        if ($limit === null) {
            return true; // unlimited
        }

        return $this->usage($tenant, $metric) < $limit;
    }

    /** How many are left before the cap. Returns null when the metric is unlimited (or no plan/catalogue). */
    public function remaining(Tenant $tenant, string $metric): ?int
    {
        $plan = $this->currentPlan($tenant);
        if ($plan === null) {
            return null;
        }

        $limit = $plan->limitFor($metric);
        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $this->usage($tenant, $metric));
    }

    /** Current metered usage for a metric in its active period. */
    public function usage(Tenant $tenant, string $metric): int
    {
        $counter = UsageCounter::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric)
            ->where('period', $this->periodFor($metric))
            ->first();

        return $counter instanceof UsageCounter ? $counter->count : 0;
    }

    /** Meter one unit of usage for a metric (safe upsert on the unique (tenant, metric, period) key). */
    public function increment(Tenant $tenant, string $metric, int $by = 1): UsageCounter
    {
        $period = $this->periodFor($metric);

        /** @var UsageCounter $counter */
        $counter = UsageCounter::query()
            ->withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['tenant_id' => $tenant->id, 'metric' => $metric, 'period' => $period]);

        $counter->tenant_id = (string) $tenant->id;
        $counter->count = ($counter->count ?? 0) + $by;
        $counter->save();

        return $counter;
    }

    /**
     * Every active plan's cap + current usage/remaining for a tenant — the shape the "current subscription"
     * endpoint returns so a client can render its plan meters honestly.
     *
     * @param  list<string>  $metrics
     * @return array<string, array{limit: int|null, used: int, remaining: int|null}>
     */
    public function usageSummary(Tenant $tenant, array $metrics): array
    {
        $summary = [];
        foreach ($metrics as $metric) {
            $summary[$metric] = [
                'limit' => $this->currentPlan($tenant)?->limitFor($metric),
                'used' => $this->usage($tenant, $metric),
                'remaining' => $this->remaining($tenant, $metric),
            ];
        }

        return $summary;
    }

    /**
     * The most permissive active plan — the default for a tenant with no subscription. "Most permissive" is the
     * highest-priced active plan (scale > growth > starter), i.e. the one with the largest caps.
     */
    public function mostPermissivePlan(): ?SubscriptionPlan
    {
        /** @var SubscriptionPlan|null $plan */
        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderByDesc('price_monthly')
            ->first();

        return $plan;
    }

    /** Monthly metrics roll over each calendar month; everything else is a cumulative 'total'. */
    private function periodFor(string $metric): string
    {
        return str_contains($metric, 'per_month') || str_contains($metric, 'monthly')
            ? Carbon::now()->format('Y-m')
            : 'total';
    }
}
