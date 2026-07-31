<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What CampaignsHub billed a customer (SUBINV-001).
 *
 * NOT the agency's `Invoice`, which is a tenant's document to its own client. This one is ours to the
 * tenant, and the two are kept apart because whose tax number appears on a document, whose currency
 * governs it and who may read it are all different answers.
 *
 * Every money column is STORED rather than derived. Recomputing a total from a rate at read time
 * means a VAT change silently rewrites history — wrong in general, and for a tax document the kind of
 * wrong that has consequences.
 */
final class SubscriptionInvoice extends Model
{
    use HasUuidKey;

    protected $table = 'subscription_invoices';

    // `status`, the settlement timestamps and the share token are the record of what HAPPENED, so
    // they are written by the service rather than by whatever payload reached a controller.
    protected $guarded = ['status', 'paid_at', 'refunded_at', 'voided_at', 'share_token', 'shared_at', 'amount_paid'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'tax_rate' => 'decimal:4',
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'voided_at' => 'datetime',
        'shared_at' => 'datetime',
    ];

    /** @return HasMany<SubscriptionInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SubscriptionInvoiceLine::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<RegistrationRequest, $this> */
    public function registrationRequest(): BelongsTo
    {
        return $this->belongsTo(RegistrationRequest::class, 'registration_request_id');
    }

    /** @return BelongsTo<SubscriptionPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    public function isSettled(): bool
    {
        return $this->status === 'paid';
    }

    /** What is still owed. Zero on a settled or voided document, never negative. */
    public function outstanding(): string
    {
        if (in_array($this->status, ['paid', 'void'], true)) {
            return '0.00';
        }

        return number_format(max(0, (float) $this->total - (float) $this->amount_paid), 2, '.', '');
    }

    public function isShared(): bool
    {
        return $this->share_token !== null;
    }
}
