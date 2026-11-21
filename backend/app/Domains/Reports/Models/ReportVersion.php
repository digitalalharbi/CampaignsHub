<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** REPORT-SNAPSHOT-PROTECT-001 — a stored copy of a report that a rebuild replaced. */
final class ReportVersion extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = ['tenant_id', 'project_id', 'report_id', 'data', 'generated_at'];

    protected $casts = [
        'data' => 'array',
        'generated_at' => 'datetime',
    ];
}
