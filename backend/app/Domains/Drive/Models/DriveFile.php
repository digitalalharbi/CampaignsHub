<?php

declare(strict_types=1);

namespace App\Domains\Drive\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Cached Drive file METADATA under a {@see DriveLink}. We store the Drive File ID (a reference) plus display
 * metadata — never the file bytes. Rows are upserted idempotently by (drive_link_id, file_id) and may be
 * attached to a creative/report target. Tenant-scoped; uuid PK.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $drive_link_id
 * @property string $file_id
 * @property string $name
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $thumbnail_link
 * @property string|null $web_view_link
 * @property Carbon|null $modified_time
 * @property string|null $version
 * @property string|null $attached_to_type
 * @property string|null $attached_to_id
 */
final class DriveFile extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'drive_link_id', 'file_id', 'name', 'mime', 'size',
        'thumbnail_link', 'web_view_link', 'modified_time', 'version',
        'attached_to_type', 'attached_to_id',
    ];

    protected $casts = [
        'size' => 'integer',
        'modified_time' => 'datetime',
    ];

    /** @return BelongsTo<DriveLink, $this> */
    public function driveLink(): BelongsTo
    {
        return $this->belongsTo(DriveLink::class);
    }
}
