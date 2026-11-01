<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Usage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PRICING-USAGE-CREDITS-001 — one metered act. Append-only, written only by {@see UsageLedger}; no tenant global scope because
 * every query names the tenant.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $meter_code
 * @property string|null $scope
 * @property int $quantity
 * @property string $funded_by
 * @property string|null $unit_cost
 * @property string $cost
 * @property string $credits_charged
 * @property string $uncovered
 * @property string $currency
 * @property string $idempotency_key
 * @property Carbon $occurred_at
 */
final class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'occurred_at' => 'datetime'];
}
