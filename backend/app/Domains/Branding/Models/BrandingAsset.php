<?php

declare(strict_types=1);

namespace App\Domains\Branding\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored brand file, addressed by (scope, scope_id, kind, theme) — unique within a tenant. The bytes live
 * on a PRIVATE disk; `path`/`original_path` are internal and are never exposed by the API (an opaque id + a
 * download URL are returned instead). Tenant-scoped; uuid PK.
 */
final class BrandingAsset extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'kind', 'theme', 'disk', 'path', 'original_path',
        'mime', 'width', 'height', 'bytes', 'checksum', 'created_by',
    ];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'bytes' => 'integer',
    ];
}
