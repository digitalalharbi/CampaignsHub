<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Observers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\Subscription;

/**
 * Every change to a subscription is recorded, and it cannot be forgotten (OPS-002).
 *
 * `SubscriptionLifecycle` moves an account through a trial, a conversion, a renewal, a failed charge,
 * a grace period, a suspension, a cancellation, a reactivation and a plan change — sixteen public
 * methods, each computing WHY it is acting and then throwing that reason away. Nothing about any of it
 * reached the audit trail: an owner could see that a workspace was suspended and had no way to find
 * out when, by whom, or on what grounds.
 *
 * This is an observer rather than a call at each site on purpose. The lifecycle mutates subscriptions
 * from about ten different places, most of them running unattended on a schedule, and the pattern
 * where somebody adds an eleventh and forgets the audit line is exactly how a trail develops holes
 * that nobody notices until it is needed. An observer is the difference between «we remembered
 * everywhere» and «it cannot be missed».
 *
 * Only the columns that change what a customer is owed or charged are audited. `current_period_end`
 * moves on every renewal and `updated_at` on every write; recording those would bury the suspension
 * that matters under a thousand rows that do not.
 */
final class SubscriptionAuditObserver
{
    /**
     * The columns worth a permanent record: what plan, what price, what state, and whether it is set
     * to stop. A change to any of these is a change to the commercial relationship.
     */
    private const MATERIAL = [
        'status', 'plan_id', 'unit_amount', 'currency', 'billing_interval', 'cancel_at_period_end',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function created(Subscription $subscription): void
    {
        $this->audit->log(
            action: 'subscription.created',
            entityType: 'subscription',
            entityId: (string) $subscription->getKey(),
            after: $this->snapshot($subscription->getAttributes()),
            reason: $this->reasonOf($subscription),
            tenantId: $subscription->tenant_id === null ? null : (string) $subscription->tenant_id,
        );
    }

    public function updated(Subscription $subscription): void
    {
        $changed = array_intersect(array_keys($subscription->getChanges()), self::MATERIAL);
        if ($changed === []) {
            return;
        }

        $before = [];
        $after = [];
        foreach ($changed as $column) {
            $before[$column] = $subscription->getOriginal($column);
            $after[$column] = $subscription->getAttribute($column);
        }

        $this->audit->log(
            // A status move is the event people look for, so it gets its own action rather than being
            // one of a hundred generic «updated» rows to be filtered through.
            action: in_array('status', $changed, true) ? 'subscription.status_changed' : 'subscription.terms_changed',
            entityType: 'subscription',
            entityId: (string) $subscription->getKey(),
            before: $before,
            after: $after,
            reason: $this->reasonOf($subscription),
            tenantId: $subscription->tenant_id === null ? null : (string) $subscription->tenant_id,
        );
    }

    /**
     * The reason the lifecycle already computed, when it set one.
     *
     * `SubscriptionLifecycle` takes a `$why` on suspend, cancel, reactivate and past-due, and assigns
     * it to `auditReason` before saving. Where no reason was set the entry still records WHAT changed;
     * an unexplained change is worth having, and pretending to a reason would be worse than none.
     */
    private function reasonOf(Subscription $subscription): ?string
    {
        $why = $subscription->auditReason ?? null;

        return is_string($why) && $why !== '' ? $why : null;
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function snapshot(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::MATERIAL));
    }
}
