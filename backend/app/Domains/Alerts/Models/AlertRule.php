<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant-configured alerting rule. Tenant-scoped (project_id null = tenant-wide). Never project-scoped via
 * BelongsToProject because rules must be evaluatable outside a request's project context (by the scheduler).
 *
 * ALERT-RULES-MANAGE-001 — soft-deleted, so removing a rule keeps the alerts it raised (their FK cascades), and
 * `recipients` ({project_manager: bool, user_ids: int[]}) narrows who is emailed; null = everyone in scope.
 */
final class AlertRule extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'type', 'name', 'threshold', 'cooldown_minutes',
        'channels', 'create_task', 'severity', 'active', 'created_by', 'recipients',
    ];

    protected $casts = [
        'threshold' => 'array',
        'channels' => 'array',
        'recipients' => 'array',
        'create_task' => 'boolean',
        'active' => 'boolean',
        'cooldown_minutes' => 'integer',
    ];
}
