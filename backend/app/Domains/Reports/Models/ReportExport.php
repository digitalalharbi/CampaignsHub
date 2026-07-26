<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

final class ReportExport extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'report_id', 'format', 'status', 'disk', 'path', 'size',
        'signed_token', 'expires_at', 'error', 'created_by', 'is_demo',
        'renderer', 'renderer_version', 'template_version', 'snapshot_checksum',
        'locale', 'layout_mode', 'validation_status',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'size' => 'integer',
        'is_demo' => 'boolean',
    ];
}
