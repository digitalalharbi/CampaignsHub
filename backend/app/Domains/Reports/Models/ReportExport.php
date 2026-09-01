<?php

declare(strict_types=1);

namespace App\Domains\Reports\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * The report this file is OF.
     *
     * REPORT-TITLE-METADATA-001 — the download names the report rather than the blob on disk, and it
     * needs the report to do that. `withoutGlobalScopes` is deliberate: an export is fetched on a
     * download route where the export row itself is the authorisation, and the tenant scope would
     * silently return null for the very row that was just authorised.
     *
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class)->withoutGlobalScopes();
    }
}
