<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Services;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

/**
 * The one place that answers "what may somebody buy, and on what terms?" (PLAN-001).
 *
 * The contract's requirement is that plans are a central engine rather than fixed arrays, and the
 * reason is practical: the price a visitor is shown, the amount a checkout charges, the limits the
 * backend enforces and the date a renewal falls due all have to be the same statement. Where each of
 * those reads its own literal, they drift, and the first symptom is a customer charged an amount they
 * were never quoted.
 *
 * Nothing here decides anything. It reads the catalogue and refuses to guess: an unknown plan code
 * comes back null, and a term a plan is not sold on has no price rather than the other term's price.
 */
final class PlanCatalogue
{
    /**
     * The plans a visitor may choose when signing up.
     *
     * `is_public` is deliberately separate from `is_active`: a plan withdrawn from sale must keep
     * working for everyone already on it. Conflating the two either strands those customers or keeps
     * offering something we no longer sell.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    public function offered(): Collection
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('price_monthly')
            ->get();
    }

    /** Every plan, including withdrawn ones — the platform owner's view. */
    public function all(): Collection
    {
        return SubscriptionPlan::query()->orderBy('sort_order')->orderBy('price_monthly')->get();
    }

    public function byCode(?string $code): ?SubscriptionPlan
    {
        if ($code === null || $code === '') {
            return null;
        }

        return SubscriptionPlan::query()->where('code', $code)->first();
    }

    /** True when this code names a plan somebody is allowed to sign up for right now. */
    public function isOffered(?string $code): bool
    {
        $plan = $this->byCode($code);

        return $plan !== null && $plan->is_active && $plan->is_public;
    }

    /**
     * What signing up for this plan on this term actually commits the customer to.
     *
     * One structure, used by the pricing page, the registration status screen and the checkout, so
     * the figure quoted and the figure charged cannot disagree. Returns null when the plan is not
     * sold on the requested term.
     *
     * @return array{
     *     plan_code: string, currency: string, interval: string,
     *     due_now: string, due_later: string|null, renews_in_days: int,
     *     trial_days: int, trial_fee: string|null
     * }|null
     */
    public function quote(SubscriptionPlan $plan, string $interval): ?array
    {
        $recurring = $plan->priceFor($interval);

        if ($recurring === null) {
            return null;
        }

        $trial = $plan->offersTrial();

        return [
            'plan_code' => $plan->code,
            'currency' => $plan->currency,
            'interval' => $interval,
            /*
             * What is taken TODAY. On a trial that is the symbolic trial fee, and the subscription
             * price is what falls due when the trial converts — quoting the subscription price as
             * "due now" would misstate the charge the customer is about to authorise.
             */
            'due_now' => $trial ? (string) $plan->trial_fee : $recurring,
            'due_later' => $trial ? $recurring : null,
            'renews_in_days' => $trial ? $plan->trial_days : ($interval === 'annual' ? 365 : 30),
            'trial_days' => $plan->trial_days,
            'trial_fee' => $trial ? (string) $plan->trial_fee : null,
        ];
    }

    /**
     * The catalogue as an interface renders it.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(bool $offeredOnly = true): array
    {
        return ($offeredOnly ? $this->offered() : $this->all())
            ->map(fn (SubscriptionPlan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
                'name_ar' => $plan->name_ar ?? $plan->name,
                'summary_ar' => $plan->summary_ar,
                'summary_en' => $plan->summary_en,
                'currency' => $plan->currency,
                'price_monthly' => (string) $plan->price_monthly,
                'price_annual' => $plan->price_annual === null ? null : (string) $plan->price_annual,
                'trial_days' => $plan->trial_days,
                'trial_fee' => (string) $plan->trial_fee,
                'features' => $plan->features,
                'limits' => $plan->limits,
                'trial_limits' => $plan->trial_limits,
                'is_active' => $plan->is_active,
                'is_public' => $plan->is_public,
                'sort_order' => $plan->sort_order,
            ])->values()->all();
    }
}
