<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Addons;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRICING-ADDONS-001 — an add-on a workspace holds or asked for. `requested` grants nothing; only `active` counts.
 *
 * @property string $tenant_id
 * @property string $addon_id
 * @property int $quantity
 * @property string $status requested|active|declined|cancelled
 */
final class TenantAddon extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'addon_id', 'quantity', 'status', 'unit_price', 'currency', 'source', 'reason', 'requested_by', 'decided_by', 'activated_at', 'ended_at'];

    protected $casts = ['quantity' => 'integer', 'activated_at' => 'datetime', 'ended_at' => 'datetime'];

    /** @return BelongsTo<Addon, $this> */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }
}
