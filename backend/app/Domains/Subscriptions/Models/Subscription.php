<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's active subscription — exactly one per tenant (tenant_id is unique). Tenant-scoped, so a tenant
 * can only ever read/modify its own. Status follows trialing → active → past_due → canceled.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $status
 * @property int $seats
 */
final class Subscription extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'billing_interval', 'unit_amount', 'currency',
        'current_period_end', 'trial_ends_at', 'grace_ends_at',
        'auto_convert_consent_at', 'cancel_at_period_end', 'seats',
    ];

    /*
     * `provider`, `provider_customer_id` and `provider_subscription_id` are absent from $fillable on
     * purpose: they are written by the payment adapter that owns the subscription at the gateway, and
     * a payload able to set them could point a subscription at somebody else's customer record.
     */

    protected $casts = [
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'grace_ends_at' => 'datetime',
        'auto_convert_consent_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'unit_amount' => 'decimal:2',
        'seats' => 'integer',
    ];

    /** Is this subscription still inside its paid trial? */
    public function isTrialing(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * May this trial convert into a paid subscription?
     *
     * The contract requires consent to auto-conversion to be EXPLICIT, so the absence of a recorded
     * agreement is a refusal — converting anyway would be a charge nobody authorised.
     */
    public function mayAutoConvert(): bool
    {
        return $this->auto_convert_consent_at !== null && ! $this->cancel_at_period_end;
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
