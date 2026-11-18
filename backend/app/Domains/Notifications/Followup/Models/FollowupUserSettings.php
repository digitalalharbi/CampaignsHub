<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** NOTIF-V2-FOLLOWUP-001 — a person's morning-brief settings. */
final class FollowupUserSettings extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'followup_user_settings';

    protected $fillable = ['tenant_id', 'user_id', 'brief_enabled', 'brief_time', 'brief_channels'];

    protected $casts = ['brief_enabled' => 'boolean', 'brief_channels' => 'array'];
}
