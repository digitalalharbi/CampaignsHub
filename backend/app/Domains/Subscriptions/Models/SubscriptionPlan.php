<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A plan in the GLOBAL catalogue (starter|growth|scale). Not tenant-scoped — every tenant selects from the
 * same catalogue. `limits` holds the per-metric caps ({projects, team_members, connections,
 * reports_per_month, ...}); a null or absent value for a metric means unlimited.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property array<string,mixed>|null $features
 * @property array<string,int|null>|null $limits
 * @property bool $is_active
 */
final class SubscriptionPlan extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'code', 'name', 'name_ar', 'summary_ar', 'summary_en',
        'price_monthly', 'price_annual', 'currency',
        'trial_fee', 'trial_days', 'trial_limits', 'minimum_commitment_months',
        'features', 'limits', 'is_active', 'is_public', 'contact_sales', 'sort_order',
    ];

    protected $casts = [
        'price_monthly' => 'decimal:2',
        'price_annual' => 'decimal:2',
        'trial_fee' => 'decimal:2',
        'trial_days' => 'integer',
        'minimum_commitment_months' => 'integer',
        'trial_limits' => 'array',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'is_public' => 'boolean',
        'contact_sales' => 'boolean',
        'sort_order' => 'integer',
    ];

    /** Is this plan sold on an annual term at all? */
    public function hasAnnualTerm(): bool
    {
        return $this->price_annual !== null;
    }

    /** Does signing up for this plan begin with a paid trial? */
    public function offersTrial(): bool
    {
        return $this->trial_days > 0;
    }

    /**
     * Does this term begin with the paid introductory period? — PAY-AUDIT-003.
     *
     * The introductory month is a MONTHLY offer. Somebody committing to a year is already committing,
     * and the annual price already carries its own discount; putting a symbolic first month in front
     * of it would discount the discount and delay the year they asked to buy.
     *
     * So the answer depends on the term, which `offersTrial()` alone cannot express — it reads the
     * plan and not the purchase.
     */
    public function offersIntroFor(string $interval): bool
    {
        return $interval === 'monthly' && $this->offersTrial();
    }

    /**
     * How many months this term is committed for — SUB-COMMIT-001.
     *
     * The commitment belongs to the INTRODUCTORY OFFER, so it applies exactly where the offer does:
     * the monthly term, on a plan that has one. An annual subscriber has already paid for a year up
     * front and committing them on top of that would be charging twice for the same promise.
     *
     * A plan with no commitment configured returns 0, which is «cancel whenever you like» and is a
     * perfectly ordinary way to sell a subscription — the column defaults to it.
     */
    public function commitmentMonthsFor(string $interval): int
    {
        return $this->offersIntroFor($interval) ? max(0, (int) $this->minimum_commitment_months) : 0;
    }

    /**
     * What the customer is committing to spend in total, in this plan's currency.
     *
     * The introductory month plus the remaining months at the FULL price — the figure the contract
     * asks to be shown before payment, and the one nobody works out for themselves from «9 now, then
     * 149 a month, minimum three months».
     */
    public function totalCommittedFor(string $interval): ?string
    {
        $months = $this->commitmentMonthsFor($interval);

        if ($months === 0) {
            return null;
        }

        $total = (float) $this->trial_fee + ((float) $this->price_monthly * ($months - 1));

        return number_format($total, 2, '.', '');
    }

    /**
     * The amount to charge for a term, in the plan's currency.
     *
     * Returns null when the plan is not sold on that term — a caller must not fall back to the other
     * price, because charging a year's fee for a month (or the reverse) is exactly the kind of error
     * a silent default produces.
     */
    public function priceFor(string $interval): ?string
    {
        return match ($interval) {
            'monthly' => (string) $this->price_monthly,
            'annual' => $this->price_annual === null ? null : (string) $this->price_annual,
            default => null,
        };
    }

    /**
     * The cap for a metric while a subscription is in its TRIAL.
     *
     * Falls back to the plan's own cap, because a trial that does not narrow a metric is on the
     * plan's terms for it — an absent trial limit is "same as the plan", not "unlimited".
     */
    public function trialLimitFor(string $metric): ?int
    {
        if ($this->trial_limits !== null && array_key_exists($metric, $this->trial_limits)) {
            $value = $this->trial_limits[$metric];

            return $value === null ? null : (int) $value;
        }

        return $this->limitFor($metric);
    }

    /** The cap for a metric, or null when the plan does not limit it (unlimited). */
    public function limitFor(string $metric): ?int
    {
        $value = $this->limits[$metric] ?? null;

        return $value === null ? null : (int) $value;
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
