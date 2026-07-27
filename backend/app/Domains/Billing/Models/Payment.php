<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to collect an invoice through a gateway. idempotency_key is unique so a retried startPayment
 * never double-charges. status stays `pending` until a VERIFIED webhook advances it. Tenant-scoped; uuid PK.
 */
final class Payment extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'invoice_id', 'provider', 'provider_session_id', 'provider_payment_id',
        'amount', 'currency', 'status', 'idempotency_key', 'error', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return HasMany<PaymentAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }
}
