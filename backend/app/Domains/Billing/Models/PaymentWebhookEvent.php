<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * The received-webhook ledger. event_id is unique so a re-delivered event is a no-op (idempotent). `verified`
 * records whether the adapter authenticated the payload — only a verified event may move money. Tenant-scoped
 * (tenant_id nullable: an unmatched/unverified event may arrive before we can attribute it). uuid PK.
 */
final class PaymentWebhookEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'provider', 'event_id', 'type', 'verified', 'payload', 'processed_at',
    ];

    protected $casts = [
        'verified' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];
}
