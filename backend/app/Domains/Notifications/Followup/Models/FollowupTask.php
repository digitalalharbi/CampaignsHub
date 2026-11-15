<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** NOTIF-V2-FOLLOWUP-001 — one automatic follow-up task (spec §6). The assistant opens and closes these; it never edits a budget or a campaign. */
final class FollowupTask extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'followup_tasks';

    protected $fillable = ['tenant_id', 'project_id', 'kind', 'reason', 'params', 'event_key', 'object_type', 'object_id', 'dedup_key', 'due_on', 'status', 'snoozed_until', 'assigned_user_id', 'action_url', 'opened_at', 'closed_at', 'closed_by', 'closed_how'];

    protected $casts = ['params' => 'array', 'due_on' => 'date', 'snoozed_until' => 'datetime', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function isLive(): bool
    {
        return in_array($this->status, ['open', 'snoozed'], true);
    }
}
