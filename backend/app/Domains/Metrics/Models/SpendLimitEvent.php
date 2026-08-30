<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A threshold that was crossed, with the figures as they stood at the crossing.
 *
 * Kept rather than recomputed: spend for a past day can still change — a platform restates, a rate
 * arrives late — and an audit trail whose entries move is not an audit trail. The unique index on
 * (limit, threshold) is also the dedup, so 80% is announced once rather than on every sweep for the
 * rest of the period.
 */
final class SpendLimitEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'spend_limit_id', 'threshold', 'consumed', 'limit_amount', 'currency', 'crossed_at',
    ];

    protected $casts = [
        'threshold' => 'integer',
        'consumed' => 'float',
        'limit_amount' => 'float',
        'crossed_at' => 'datetime',
    ];
}
