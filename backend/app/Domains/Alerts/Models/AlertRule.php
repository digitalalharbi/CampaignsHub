<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant-configured alerting rule. Tenant-scoped (project_id null = tenant-wide). Never project-scoped via
 * BelongsToProject because rules must be evaluatable outside a request's project context (by the scheduler).
 */
final class AlertRule extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'type', 'name', 'threshold', 'cooldown_minutes',
        'channels', 'create_task', 'severity', 'active', 'created_by',
    ];

    protected $casts = [
        'threshold' => 'array',
        'channels' => 'array',
        'create_task' => 'boolean',
        'active' => 'boolean',
        'cooldown_minutes' => 'integer',
    ];
}
