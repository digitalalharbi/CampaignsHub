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
        'tenant_id', 'plan_id', 'status', 'current_period_end', 'seats',
    ];

    protected $casts = [
        'current_period_end' => 'datetime',
        'seats' => 'integer',
    ];

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
