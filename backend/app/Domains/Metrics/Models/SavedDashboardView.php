<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's saved dashboard view: the full filter set + date range + comparison, persisted server-side
 * (DASH-010-E). Tenant-scoped (BelongsToTenant global scope) AND owned by a single user — controllers
 * additionally constrain by user_id so one user never sees another's views.
 */
final class SavedDashboardView extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'user_id', 'name', 'module', 'filters', 'date_range', 'comparison', 'sort_order', 'is_default',
    ];

    protected $casts = [
        'filters' => 'array',
        'date_range' => 'array',
        'comparison' => 'array',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
    ];
}
