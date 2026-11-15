<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Models;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CLIENT-CONTENT-APPROVAL-001 — content the agency asked its client to approve. Tenant-scoped.
 *
 * @property string $status
 */
final class ContentApproval extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const STATUSES = ['pending', 'approved', 'changes_requested', 'withdrawn'];

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'creative_id', 'title', 'caption', 'status',
        'requested_by', 'decided_by_user_id', 'decided_by_contact', 'decided_at', 'decision_note',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    /** @return BelongsTo<ExternalCreative, $this> */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(ExternalCreative::class, 'creative_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ClientWorkspace, $this> */
    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }
}
