<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * INTEG-SYNC-ENGINE-001 — the last window of one binding (account in a project) and one grain that was fully read and written.
 *
 * Not `external_accounts.last_synced_at`, which is an attempt stamp a partial run also moves. See `SyncCheckpoints` for the rules.
 */
final class MetricSyncCheckpoint extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider', 'grain', 'window_from', 'window_to',
        'completed_at', 'metric_sync_run_id', 'unread_from', 'unread_to', 'resume_attempted_at',
    ];

    protected $casts = [
        'window_from' => 'date',
        'window_to' => 'date',
        'completed_at' => 'datetime',
        'unread_from' => 'date',
        'unread_to' => 'date',
        'resume_attempted_at' => 'datetime',
    ];
}
