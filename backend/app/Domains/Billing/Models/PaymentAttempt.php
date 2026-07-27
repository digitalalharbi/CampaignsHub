<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only audit row for one provider round-trip on a payment (createSession or webhook). Carries the
 * raw provider response for forensics. Tenant-scoped; write-once, so it tracks only created_at.
 */
final class PaymentAttempt extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'payment_id', 'status', 'provider_response',
    ];

    protected $casts = [
        'provider_response' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
