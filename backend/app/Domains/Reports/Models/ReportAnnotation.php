<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A persisted finding/recommendation with an approval lifecycle. Client/executive reports render only
 * `approved` records; the internal report can render every status per permissions.
 */
final class ReportAnnotation extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const STATUSES = ['draft', 'reviewed', 'approved', 'hidden', 'rejected'];

    protected $fillable = [
        'tenant_id', 'report_id', 'annotation_id', 'type', 'text_ar', 'text_en', 'platform', 'campaign_id',
        'kpi', 'evidence', 'source', 'priority', 'proposed_action', 'assignee_id', 'due_date', 'status',
        'is_ai_generated', 'version', 'created_by', 'reviewed_by', 'approved_by', 'rejected_by',
        'reviewed_at', 'approved_at', 'rejected_at', 'is_demo',
    ];

    protected $casts = [
        'evidence' => 'array',
        'is_ai_generated' => 'boolean',
        'is_demo' => 'boolean',
        'version' => 'integer',
        'due_date' => 'date',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];
}
