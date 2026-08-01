<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Support\Carbon;

/**
 * What a mid-term plan change costs (PAY-002).
 *
 * Kept apart from the lifecycle on purpose: this is the only part of a plan change that is pure
 * arithmetic on money, and it is the part that must be provable. Every other step — opening a
 * charge, swapping the plan, telling the customer — depends on getting these numbers right, so they
 * are computed somewhere they can be checked without a database, a gateway or a webhook.
 *
 * The rule it encodes, in one sentence: **the customer pays the difference for the time they have
 * not used yet, and never pays twice for time they have already bought.**
 */
final class SubscriptionProration
{
    /**
     * The numbers behind a proposed change.
     *
     * @return array{
     *   direction: string, remaining_days: int, period_days: int, unused_fraction: float,
     *   credit: string, new_period_price: string, prorated_new: string, due_now: string,
     *   currency: string, effective: string, effective_at: ?string,
     * }
     */
    public function quote(
        Subscription $subscription,
        SubscriptionPlan $newPlan,
        string $interval,
        ?Carbon $now = null,
    ): array {
        $now = $now ?? Carbon::now();
        $currency = (string) ($subscription->currency ?? config('subscriptions.currency'));

        $periodStart = $subscription->current_period_start ?? $now;
        $periodEnd = $subscription->current_period_end;

        /*
         * Whole days, and the remainder is rounded UP.
         *
         * A customer upgrading at nine in the morning has not used that day yet, and charging them
         * for it would be taking money for time they still have. Rounding the other way is the kind
         * of quiet arithmetic that ends up in a complaint nobody can answer.
         */
        $periodDays = $periodEnd === null ? 0 : max(0, (int) ceil($periodStart->diffInDays($periodEnd, absolute: false)));
        $remainingDays = $periodEnd === null ? 0 : max(0, (int) ceil($now->diffInDays($periodEnd, absolute: false)));
        $remainingDays = min($remainingDays, $periodDays);

        // No period, or a period already over: nothing is left to prorate, and the change is simply
        // the next period's price.
        $unused = $periodDays > 0 ? $remainingDays / $periodDays : 0.0;

        $currentPrice = (float) ($subscription->unit_amount ?? 0);
        $newPeriodPrice = (float) ($newPlan->priceFor($interval) ?? 0);

        $credit = round($currentPrice * $unused, 2);
        $proratedNew = round($newPeriodPrice * $unused, 2);
        $difference = round($proratedNew - $credit, 2);

        /*
         * Which way this goes is decided by the PERIOD price, not by the prorated difference.
         *
         * On the last day of a period the prorated difference between two plans rounds to nothing,
         * and treating that as a downgrade would apply a more expensive plan immediately for free.
         * The direction is a property of the two prices; the fraction only decides what is owed.
         */
        $direction = match (true) {
            $newPeriodPrice > $currentPrice => 'upgrade',
            $newPeriodPrice < $currentPrice => 'downgrade',
            default => 'lateral',
        };

        /*
         * An upgrade is immediate and paid for; a downgrade waits for the period end.
         *
         * Applying a downgrade at once would take away capability the customer has already paid for
         * and keep the money — and this platform cannot refund the difference in any case while the
         * gateways hold no credentials. A lateral move (same price, different interval or a renamed
         * plan) is immediate because nothing is owed either way.
         */
        $effective = $direction === 'downgrade' ? 'period_end' : 'immediate';

        return [
            'direction' => $direction,
            'remaining_days' => $remainingDays,
            'period_days' => $periodDays,
            'unused_fraction' => round($unused, 6),
            'credit' => $this->money($credit),
            'new_period_price' => $this->money($newPeriodPrice),
            'prorated_new' => $this->money($proratedNew),
            // Never negative: money owed BACK is not taken here, it is honoured by letting the
            // customer keep the period they paid for.
            'due_now' => $this->money(max(0.0, $difference)),
            'currency' => $currency,
            'effective' => $effective,
            'effective_at' => $effective === 'period_end' ? $periodEnd?->toIso8601String() : null,
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
