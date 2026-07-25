<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One metrics sync attempt for an account/window. Tenant + project scoped. Records status, timing,
 * upsert count and errors so resyncs are idempotent and connector failures are observable.
 */
final class MetricSyncRun extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'connection_id', 'external_account_id', 'provider',
        'status', 'window_start', 'window_end', 'metrics_upserted', 'attempts',
        'started_at', 'finished_at', 'error', 'meta',
    ];

    protected $casts = [
        'window_start' => 'date',
        'window_end' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics_upserted' => 'integer',
        'attempts' => 'integer',
        'meta' => 'array',
    ];
}
