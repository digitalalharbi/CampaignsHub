<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Report extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'campaign_id', 'name', 'type', 'form', 'audience', 'mode', 'version', 'campaign_objective', 'status',
        'period_start', 'period_end', 'currency', 'timezone', 'attribution_window', 'data_source',
        'config', 'data', 'error', 'created_by', 'generated_at', 'last_sent_at', 'is_demo',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'config' => 'array',
        'data' => 'array',
        'generated_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'is_demo' => 'boolean',
    ];

    /** @return HasMany<ReportExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    /** @return HasMany<ReportRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(ReportRecipient::class);
    }
}
