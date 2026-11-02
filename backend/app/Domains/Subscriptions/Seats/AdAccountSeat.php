<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Seats;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PRICING-SEATS-001 — one ad account a workspace has held, by provider and real id. Written only by AdAccountSeats, under the
 * tenant row lock; no tenant global scope because every query names the tenant.
 *
 * @property string $tenant_id
 * @property string $provider
 * @property string $external_id
 * @property string $status active|released
 * @property Carbon|null $released_at
 * @property Carbon|null $replaced_at
 */
final class AdAccountSeat extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'provider', 'external_id', 'status', 'first_bound_at', 'last_bound_at', 'released_at', 'replaced_at'];

    protected $casts = ['first_bound_at' => 'datetime', 'last_bound_at' => 'datetime', 'released_at' => 'datetime', 'replaced_at' => 'datetime'];
}
