<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A provider screenshot attached during review (WD-EVIDENCE-001) — trust beside the figures, never the
 * source of them. The calculations stay with the structured provider data.
 *
 * @property string $id
 * @property string $provider
 * @property string $disk
 * @property string $path
 */
final class PlatformEvidence extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** «evidence» is uncountable to Laravel's pluraliser, which would look for `platform_evidence`. */
    protected $table = 'platform_evidences';

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'provider', 'period_start', 'period_end', 'disk', 'path',
        'original_name', 'mime', 'size', 'captured_at', 'uploaded_by', 'note', 'include_in_report',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'captured_at' => 'datetime',
        'size' => 'integer',
        'include_in_report' => 'boolean',
    ];
}
