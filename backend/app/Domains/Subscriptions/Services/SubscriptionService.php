<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Models\UsageCounter;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The subscription / plan-limit engine. It is honest and fail-open by construction:
 *
 *   - A tenant WITHOUT a subscription defaults to the MOST PERMISSIVE active plan, so nothing regresses when
 *     subscriptions are not yet provisioned (see {@see currentPlan()} and {@see mostPermissivePlan()}).
 *   - withinLimit() compares what the tenant is ACTUALLY using against the plan's cap for a metric. A
 *     null/absent cap means unlimited → always within.
 *   - usage() counts the thing itself — projects, campaigns, seats, connections, this month's reports —
 *     rather than reading a meter somebody has to remember to feed. See usage() for why that was the
 *     whole bug: the meter had no callers, so every cap passed everything (PAY-AUDIT-001).
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

    /**
     * The cap that applies to this tenant RIGHT NOW (PLAN-001, PAY-003).
     *
     * A subscription inside its trial is capped by the plan's trial limits, which are tighter — a
     * trial is a look at the product, not a week of the whole thing. Outside a trial it is the plan's
     * own cap. Reading `limitFor` everywhere would have handed every trial the full plan, which is
     * both a cost and an unpleasant surprise when the trial ends and the workspace suddenly exceeds
     * what it is allowed.
     */
    public function effectiveLimit(Tenant $tenant, string $metric): ?int
    {
        $plan = $this->currentPlan($tenant);

        if ($plan === null) {
            return null;
        }

        return $this->subscriptionFor($tenant)?->isTrialing()
            ? $plan->trialLimitFor($metric)
            : $plan->limitFor($metric);
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
    public function assignPlan(Tenant $tenant, SubscriptionPlan $plan, string $status = 'active', ?Carbon $currentPeriodEnd = null, ?int $seats = null, string $interval = 'monthly'): Subscription
    {
        /** @var Subscription $subscription */
        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['tenant_id' => $tenant->id]);

        /*
         * The price is captured HERE, not read from the plan at renewal (PLAN-001).
         *
         * A subscription that is only a pointer at a catalogue row means editing a price in /admin
         * silently re-prices everyone already on that plan. The catalogue governs what new customers
         * are quoted; this column governs what an existing one owes, and changing one does not move
         * the other.
         */
        $subscription->fill([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_interval' => $interval,
            'unit_amount' => $plan->priceFor($interval) ?? $plan->price_monthly,
            'currency' => $plan->currency,
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

        // The TRIAL cap while a trial is running, the plan's own after it — see effectiveLimit().
        $limit = $this->effectiveLimit($tenant, $metric);
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

        // Trial-aware, so "remaining" is what is actually left rather than what the plan would allow
        // once the trial ends.
        $limit = $this->effectiveLimit($tenant, $metric);
        if ($limit === null) {
            return null; // unlimited
        }

        return max(0, $limit - $this->usage($tenant, $metric));
    }

    /**
     * What the tenant is actually using — COUNTED FROM THE THING ITSELF (PAY-AUDIT-001).
     *
     * ## Why this stopped reading the meter
     *
     * This used to return `usage_counters` and nothing else, and `increment()` — the only writer of
     * that table — had no callers anywhere in `app/`. The table was written by tests and by nothing
     * else. So `usage()` returned 0 for every tenant, `withinLimit()` was always `0 < cap`, and both
     * `EnsureWithinPlanLimit` mounts passed everything through. Five caps sold, five unenforced,
     * while the docblock above claimed it compared REAL counters.
     *
     * The suite did not catch it because `SubscriptionTest` calls `increment()` itself and then
     * asserts the comparison: it proved this class's arithmetic and never that creating a project
     * moved the number.
     *
     * ## Why counting beats metering here
     *
     * A meter would have been wrong even once fed. `projects`, `campaigns`, `team_members` and
     * `connections` are STOCK, not flow — they can be archived, revoked and removed — and a
     * monotonic counter never gives the slot back. A customer who tidied up would watch the capacity
     * they had paid for ratchet away, which is worse than not enforcing at all, because it takes
     * something rather than merely failing to stop something.
     *
     * Every one of these leaves a row, so the row is the honest answer. Nothing to feed, nothing to
     * forget to feed, and no drift possible between the meter and the thing being metered.
     * `reports_per_month` is a genuine flow and is still derived — from the reports themselves,
     * within the calendar month — for the same reason.
     *
     * `usage_counters` remains for a metric that leaves no row behind. Nothing is such a metric
     * today, so nothing writes it; see {@see increment()}.
     */
    public function usage(Tenant $tenant, string $metric): int
    {
        $counted = $this->count($tenant, $metric);

        return $counted ?? $this->metered($tenant, $metric);
    }

    /**
     * The live count for a metric this service knows how to measure, or null when it does not.
     *
     * Every query drops the tenant global scope and names the tenant explicitly — the service can be
     * asked about any tenant, including from a console command with no request scope at all.
     */
    private function count(Tenant $tenant, string $metric): ?int
    {
        $id = (string) $tenant->id;

        return match ($metric) {
            // Archived is not deleted, and it is not occupying a slot either — restoring one is a
            // create as far as the cap is concerned, which is why `restore` is guarded too.
            'projects' => DB::table('projects')->where('tenant_id', $id)
                ->whereNull('deleted_at')->where('status', '!=', 'archived')->count(),

            'campaigns' => DB::table('unified_campaigns')->where('tenant_id', $id)
                ->whereNull('deleted_at')->where('status', '!=', 'archived')->count(),

            /*
             * Seats held, not accounts created.
             *
             * A pending invitation holds a seat: since TEAM-INVITE-001 it creates no `User` at all,
             * so counting memberships alone would let a workspace on a cap of three invite thirty
             * people and pass every check until the day they all accepted.
             */
            'team_members' => DB::table('memberships')->where('tenant_id', $id)->where('status', 'active')->count()
                + DB::table('workspace_invitations')->where('tenant_id', $id)
                    ->whereNull('accepted_at')->where('expires_at', '>', now())->count(),

            // A revoked connection is a connection somebody deliberately gave up; it must not go on
            // costing them a slot.
            'connections' => DB::table('provider_connections')->where('tenant_id', $id)
                ->whereNotIn('status', ['revoked', 'disconnected'])->count(),

            // The one genuine flow: what was produced this calendar month, from the reports rather
            // than from a tally of them.
            'reports_per_month' => DB::table('reports')->where('tenant_id', $id)
                ->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),

            default => null,
        };
    }

    /** The counter, for a metric nothing can count. See {@see usage()}. */
    private function metered(Tenant $tenant, string $metric): int
    {
        $counter = UsageCounter::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('metric', $metric)
            ->where('period', $this->periodFor($metric))
            ->first();

        return $counter instanceof UsageCounter ? $counter->count : 0;
    }

    /**
     * Meter one unit of usage for a metric (safe upsert on the unique (tenant, metric, period) key).
     *
     * NOTHING CALLS THIS, and that is now correct rather than a bug: every metric the product sells
     * leaves a row, so {@see usage()} counts the rows. This stays for a future metric that leaves
     * none — an API call, a delivered message — and `usage()` falls through to it automatically for
     * any metric `count()` does not recognise.
     */
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
                'limit' => $this->effectiveLimit($tenant, $metric),
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
