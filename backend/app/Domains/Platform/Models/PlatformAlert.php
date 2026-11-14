<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PLATFORM-PAYMENT-ALERTS-001 — one thing the platform owner should know about a customer on the way in.
 *
 * NOT tenant-scoped, and deliberately so: it belongs to the platform. It is read only under `auth:sanctum` +
 * `platform`, and mailed only to platform administrators. `subject_tenant_id` is the workspace it is about.
 *
 * `facts` is the evidence as it stood when the alert was raised — never a guess, never a card number or a secret.
 *
 * @property array<string,mixed> $facts
 */
final class PlatformAlert extends Model
{
    use HasUuidKey;

    public const ABANDONED = 'checkout_abandoned';

    public const UNVERIFIED = 'payment_unverified';

    public const MISMATCH = 'payment_activation_mismatch';

    public const UNSETTLED = 'settlement_missing';

    public const BLOCKING = 'customer_blocking_error';

    public const KINDS = [self::ABANDONED, self::UNVERIFIED, self::MISMATCH, self::UNSETTLED, self::BLOCKING];

    protected $table = 'platform_alerts';

    protected $guarded = ['id'];

    protected $casts = [
        'facts' => 'array',
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'emailed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function subjectTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'subject_tenant_id');
    }

    /**
     * Still waiting for the owner: neither handled by them nor cleared by the facts.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('acknowledged_at')->whereNull('resolved_at');
    }
}
