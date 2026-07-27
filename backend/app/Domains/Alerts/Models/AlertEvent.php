<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One lifecycle row per (rule, entity) firing. last_triggered_at drives cooldown; status carries the
 * open → snoozed → resolved lifecycle. Tenant-scoped.
 */
final class AlertEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'rule_id', 'type', 'entity_type', 'entity_id', 'dedup_key',
        'status', 'severity', 'context', 'notification_id', 'task_id',
        'last_triggered_at', 'snoozed_until', 'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'last_triggered_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
