<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Lang;

/**
 * PLAN-LIMITS-METER-001 — does what this workspace already has fit inside the plan it is moving to?
 *
 * A move to a plan whose cap is below the current usage is REFUSED, and the refusal says exactly what is over: «المشاريع
 * 8 من 3». Nothing is deleted, archived or switched off to make it fit — the customer decides what to remove, and may
 * change plan once it fits. A limit only ever stops ADDING; it never stops work that is already running.
 *
 * Counted metrics only. `reports_per_month` is a monthly allowance that resets, so a month's past use is not something
 * the new plan "holds"; it is checked where it is spent, by `EnsureWithinPlanLimit`.
 *
 * The cap compared against is the target plan's own plus the capacity add-ons the workspace still holds, exactly as
 * {@see SubscriptionService::effectiveLimit()} builds it. A cap the platform owner set for THIS workspace replaces the
 * plan's whatever the plan is, so a changed plan cannot make it tighter and that metric is skipped. Read from the
 * plan's `limits` — no number is written here.
 */
final class PlanLimitFit
{
    /** @var list<string> */
    public const METRICS = ['projects', 'clients', 'team_members', 'ad_accounts', 'connections'];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @return list<array{metric: string, label: string, used: int, limit: int, over: int}>
     */
    public function exceeding(Tenant $tenant, SubscriptionPlan $target): array
    {
        $over = [];

        foreach (self::METRICS as $metric) {
            if ($this->subscriptions->override($tenant, $metric)[0]) {
                continue;
            }

            $cap = $target->limitFor($metric);
            if ($cap === null) {
                continue; // unlimited on the target
            }

            $limit = $cap + $this->subscriptions->addonCapacity($tenant, $metric);
            $used = $this->subscriptions->usage($tenant, $metric);

            if ($used > $limit) {
                $over[] = [
                    'metric' => $metric,
                    'label' => Lang::has('billing.metrics.'.$metric) ? __('billing.metrics.'.$metric) : $metric,
                    'used' => $used,
                    'limit' => $limit,
                    'over' => $used - $limit,
                ];
            }
        }

        return $over;
    }
}
