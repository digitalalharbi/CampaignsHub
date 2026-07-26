<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A campaign note or recommendation with a Draft→Reviewed→Approved→Hidden/Rejected lifecycle.
 * Project- and tenant-scoped. Only `approved` recommendations may reach a client report.
 */
final class CampaignAnnotation extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'campaign_id', 'kind', 'status', 'title', 'body',
        'platform', 'kpi', 'evidence', 'priority', 'proposed_action', 'assignee_id',
        'due_date', 'created_by', 'reviewed_by', 'approved_by', 'approved_at', 'is_demo',
    ];

    protected $casts = [
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'is_demo' => 'boolean',
    ];
}
