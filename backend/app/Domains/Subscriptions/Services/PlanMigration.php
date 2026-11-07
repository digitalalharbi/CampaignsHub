<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * PRICING-MIGRATION-001 — what moving a launch-catalogue subscriber onto the current catalogue would mean, workspace by workspace.
 *
 * Read-only. For each subscription on a plan from a catalogue edition that is no longer published it states what the workspace
 * uses against the caps it has now and the caps of the plan it would move to, both prices in their own currencies (never
 * converted — no exchange rate is claimed), and the smallest plan on sale that fits. Scheduling is the console's act, through
 * {@see SubscriptionLifecycle::scheduleMigration()}; nothing here writes.
 */
final class PlanMigration
{
    /** The caps a customer feels: the ones a plan is sold on. `connections` is uncapped, `reports_per_month` is not sold. */
    public const METRICS = ['projects', 'clients', 'team_members', 'ad_accounts'];

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanCatalogue $catalogue,
    ) {}

    /** @return Collection<int, Subscription> live subscriptions on a plan whose catalogue edition is no longer published */
    public function candidates(): Collection
    {
        return Subscription::query()->withoutGlobalScopes()
            ->with(['plan.catalogueVersion', 'scheduledPlan'])
            ->whereHas('plan.catalogueVersion', fn ($q) => $q->where('status', '!=', 'published'))
            // A cancelled subscription is leaving; booking it onto a new plan would revive a contract the customer ended.
            ->where('status', '!=', 'cancelled')
            ->orderBy('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    public function describe(Subscription $subscription, Tenant $tenant): array
    {
        $suggested = $this->suggest($tenant);

        return [
            'tenant_id' => (string) $tenant->getKey(),
            'tenant_name' => $tenant->name,
            'tenant_slug' => $tenant->slug,
            'status' => $subscription->status,
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'from' => [
                'plan' => $subscription->plan?->code,
                'plan_name' => $subscription->plan?->name,
                'amount' => $subscription->unit_amount === null ? null : number_format((float) $subscription->unit_amount, 2, '.', ''),
                'currency' => $subscription->currency,
                'interval' => $subscription->billing_interval,
            ],
            'suggested' => $suggested === null ? null : $this->against($tenant, $suggested, (string) $subscription->billing_interval),
            'scheduled' => $subscription->scheduledPlan === null ? null : [
                'plan' => $subscription->scheduledPlan->code,
                'plan_name' => $subscription->scheduledPlan->name,
                'amount' => $subscription->scheduled_unit_amount === null ? null : (string) $subscription->scheduled_unit_amount,
                'currency' => $subscription->scheduledPlan->currency,
                'effective_at' => $subscription->scheduled_change_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The workspace measured against one plan.
     *
     * @return array{plan: string, plan_name: string, amount: string|null, currency: string, interval: string, fits: bool, metrics: list<array{metric: string, usage: int, current_limit: int|null, new_limit: int|null, fits: bool}>}
     */
    public function against(Tenant $tenant, SubscriptionPlan $plan, string $interval): array
    {
        $metrics = array_map(function (string $metric) use ($tenant, $plan) {
            $usage = $this->subscriptions->usage($tenant, $metric);
            $new = $plan->limitFor($metric);

            return [
                'metric' => $metric,
                'usage' => $usage,
                'current_limit' => $this->subscriptions->effectiveLimit($tenant, $metric),
                'new_limit' => $new,
                'fits' => $new === null || $usage <= $new,
            ];
        }, self::METRICS);

        $price = $plan->priceFor($interval);

        return [
            'plan' => $plan->code,
            'plan_name' => $plan->name,
            'amount' => $price === null ? null : number_format((float) $price, 2, '.', ''),
            'currency' => (string) $plan->currency,
            'interval' => $interval,
            'fits' => collect($metrics)->every(fn (array $m) => $m['fits']),
            'metrics' => $metrics,
        ];
    }

    /** The smallest plan on sale that holds everything the workspace uses today, or null — then it is a conversation. */
    public function suggest(Tenant $tenant): ?SubscriptionPlan
    {
        return $this->catalogue->offered()
            ->first(fn (SubscriptionPlan $plan) => collect(self::METRICS)->every(function (string $metric) use ($tenant, $plan) {
                $cap = $plan->limitFor($metric);

                return $cap === null || $this->subscriptions->usage($tenant, $metric) <= $cap;
            }));
    }
}
