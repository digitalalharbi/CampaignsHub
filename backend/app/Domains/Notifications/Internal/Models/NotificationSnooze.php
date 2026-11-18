<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** NOTIF-V2-INTERNAL-001 — a person put one notification aside until a time. */
final class NotificationSnooze extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'notification_user_snoozes';

    protected $fillable = ['tenant_id', 'user_id', 'notification_id', 'snoozed_until'];

    protected $casts = ['snoozed_until' => 'datetime'];
}
