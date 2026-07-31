<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge for the platform's own subscription revenue (PAY-002).
 *
 * NOT tenant-scoped, and it cannot be: the first charge a customer ever makes is the trial fee, taken
 * while they are still an applicant with no tenant at all. Reads are therefore either the payer's own
 * (found by a reference they hold) or a platform administrator's.
 *
 * `status` starts `pending` and only a VERIFIED webhook moves it to `paid`. Nothing else may write it
 * — see `ApplySubscriptionPaymentEvent`, which is the single writer.
 */
final class SubscriptionPayment extends Model
{
    use HasUuidKey;

    protected $table = 'subscription_payments';

    /*
     * `status`, `paid_at`, `provider_payment_id` and `refunded_at` are absent from $fillable on
     * purpose. They are the record of what the GATEWAY said, and a payload able to set them would be
     * a way to mark yourself paid.
     */
    protected $fillable = [
        'registration_request_id', 'tenant_id', 'subscription_id',
        'purpose', 'plan_code', 'billing_interval',
        'provider', 'provider_session_id', 'checkout_url', 'amount', 'currency', 'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /** @return BelongsTo<RegistrationRequest, $this> */
    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /** A charge that has been settled and not since reversed — the only kind that clears a gate. */
    public function isSettled(): bool
    {
        return $this->status === 'paid' && $this->refunded_at === null;
    }
}
