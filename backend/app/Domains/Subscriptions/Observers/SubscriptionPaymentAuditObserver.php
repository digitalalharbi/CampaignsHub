<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Observers;

use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Models\SubscriptionPayment;

/**
 * Every payment attempt and every change to its state is recorded (OPS-002).
 *
 * A payment is the one thing in this product a customer will dispute, and the trail has to survive
 * the dispute. Rows here are written by webhook handlers and gateway adapters — code paths that run
 * without anybody watching and are the least likely to have an audit call remembered at the site.
 *
 * What is deliberately NOT recorded: `provider_session_id`, `checkout_url` and anything else that
 * could be used to reach the gateway. The audit says a charge of this amount moved to this state at
 * this time; it is not a place to keep credentials, and an audit trail that leaks a payment session
 * is worse than the gap it was written to close.
 */
final class SubscriptionPaymentAuditObserver
{
    /** State and money. Provider identifiers are excluded — see the note above. */
    private const MATERIAL = ['status', 'amount', 'currency', 'purpose', 'plan_code', 'billing_interval'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function created(SubscriptionPayment $payment): void
    {
        $this->audit->log(
            action: 'payment.attempted',
            entityType: 'subscription_payment',
            entityId: (string) $payment->getKey(),
            after: $this->snapshot($payment->getAttributes()),
            tenantId: $payment->tenant_id === null ? null : (string) $payment->tenant_id,
        );
    }

    public function updated(SubscriptionPayment $payment): void
    {
        $changed = array_intersect(array_keys($payment->getChanges()), self::MATERIAL);
        if ($changed === []) {
            return;
        }

        $before = [];
        $after = [];
        foreach ($changed as $column) {
            $before[$column] = $payment->getOriginal($column);
            $after[$column] = $payment->getAttribute($column);
        }

        $this->audit->log(
            action: 'payment.status_changed',
            entityType: 'subscription_payment',
            entityId: (string) $payment->getKey(),
            before: $before,
            after: $after,
            // The gateway's own failure text, where there is one. It is the only account of WHY a
            // charge did not go through, and without it a refusal is indistinguishable from a bug.
            reason: is_string($payment->error) && $payment->error !== '' ? $payment->error : null,
            tenantId: $payment->tenant_id === null ? null : (string) $payment->tenant_id,
        );
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function snapshot(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip(self::MATERIAL));
    }
}
