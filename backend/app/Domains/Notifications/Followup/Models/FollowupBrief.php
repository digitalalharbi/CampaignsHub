<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Followup\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** NOTIF-V2-FOLLOWUP-001 — the morning brief as it was sent to one person on one day. */
final class FollowupBrief extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'followup_briefs';

    protected $fillable = ['tenant_id', 'user_id', 'brief_date', 'sections', 'summary'];

    protected $casts = ['sections' => 'array', 'brief_date' => 'date'];
}
