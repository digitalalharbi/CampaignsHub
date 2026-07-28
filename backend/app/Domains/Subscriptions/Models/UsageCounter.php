<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A metered usage counter for a (tenant, metric, period). `period` is 'total' for cumulative metrics
 * (e.g. projects) or 'YYYY-MM' for monthly metrics (e.g. reports_per_month). Tenant-scoped; bigint PK.
 * Unique per (tenant, metric, period) so an increment is a safe upsert.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $metric
 * @property string $period
 * @property int $count
 */
final class UsageCounter extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'metric', 'period', 'count',
    ];

    protected $casts = [
        'count' => 'integer',
    ];
}
