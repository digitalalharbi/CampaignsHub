<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** NOTIF-V2-FOLLOWUP-001 — the history of a task (opened, assigned, snoozed, closed, reopened, and by whom). Append-only. */
final class FollowupTaskEvent extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $table = 'followup_task_events';

    protected $fillable = ['tenant_id', 'task_id', 'action', 'actor_user_id', 'actor_label', 'detail'];

    protected $casts = ['detail' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        self::updating(static fn () => throw new LogicException('Follow-up history is append-only.'));
        self::deleting(static fn () => throw new LogicException('Follow-up history is append-only.'));
    }
}
