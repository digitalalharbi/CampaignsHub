<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** Tenant/project/user-scoped in-app notification. */
final class AppNotification extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'app_notifications';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'user_id', 'type', 'severity',
        'title', 'message', 'source', 'entity_type', 'entity_id', 'action_url', 'status',
        'dedup_key', 'read_at', 'snoozed_until', 'resolved_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
