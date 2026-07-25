<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class ReportSchedule extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'report_id', 'name', 'type', 'frequency', 'day', 'time',
        'config', 'active', 'last_run_at', 'next_run_at', 'created_by', 'is_demo',
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'is_demo' => 'boolean',
    ];
}
