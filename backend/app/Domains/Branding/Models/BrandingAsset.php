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

    /**
     * BRANDING-HIERARCHY-001 — a `platform`-scope row belongs to no tenant.
     *
     * `BelongsToTenant` auto-fills `tenant_id` from the request's context, which is right for every
     * other row in this table and wrong for this one: the product's own mark is not a customer's,
     * and stored under whoever uploaded it the platform layer could only ever answer for them. That
     * is what made the documented client → agency → CampaignsHub fallback unreachable for everybody
     * else.
     *
     * Cleared HERE rather than by weakening the trait — the auto-fill is the backbone of tenant
     * isolation, and the exception belongs with the one scope that has a reason for it.
     */
    protected static function boot(): void
    {
        parent::boot();

        $detach = static function (self $model): void {
            if ($model->getAttribute('scope') === 'platform') {
                $model->setAttribute('tenant_id', null);
            }
        };

        self::creating($detach);
        self::updating($detach);
    }
}
