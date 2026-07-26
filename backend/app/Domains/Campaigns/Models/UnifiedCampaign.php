<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The business campaign inside the system. Project- and tenant-scoped. Groups one or more
 * {@see ExternalCampaign} objects imported from ad platforms.
 */
final class UnifiedCampaign extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'project_id', 'client_workspace_id', 'name', 'client_display_name', 'objective', 'status',
        'stage', 'performance_label', 'priority',
        'total_budget', 'budget_currency', 'starts_on', 'ends_on', 'primary_conversion_purpose',
        'attribution_model', 'attribution_window', 'owner_id', 'target_kpi', 'audience', 'regions',
        'meta', 'created_by',
    ];

    protected $casts = [
        'total_budget' => 'decimal:4',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'target_kpi' => 'array',
        'regions' => 'array',
        'meta' => 'array',
    ];

    /** @return HasMany<ExternalCampaign, $this> */
    public function externalCampaigns(): HasMany
    {
        return $this->hasMany(ExternalCampaign::class);
    }
}
