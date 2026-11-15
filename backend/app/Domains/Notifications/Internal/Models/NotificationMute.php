<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** NOTIF-V2-INTERNAL-001 — a person muted one type of notification for one project. */
final class NotificationMute extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'notification_mutes';

    protected $fillable = ['tenant_id', 'user_id', 'project_id', 'kind'];

    protected $casts = [];
}
