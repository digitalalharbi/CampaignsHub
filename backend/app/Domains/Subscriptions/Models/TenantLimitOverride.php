<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SUPERADMIN-LIMITS-001 — one workspace's own cap for one metric, set from the console with a reason.
 *
 * `limit_value` null means unlimited. No tenant global scope: it is written only from the platform console and read by
 * SubscriptionService, which always names the tenant.
 *
 * @property string $tenant_id
 * @property string $metric
 * @property int|null $limit_value
 */
final class TenantLimitOverride extends Model
{
    protected $fillable = ['tenant_id', 'metric', 'limit_value', 'reason', 'set_by', 'expires_at'];

    protected $casts = ['limit_value' => 'integer', 'expires_at' => 'datetime'];
}
