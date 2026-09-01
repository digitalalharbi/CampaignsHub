<?php

declare(strict_types=1);

namespace App\Domains\Branding\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Non-file brand configuration for a scope: the palette, the type stack, and the white-label flag. One row per
 * (scope, scope_id) within a tenant. Whether white_label is actually *permitted* is a subscription concern
 * decided upstream — this model only stores the boolean it is handed. Tenant-scoped; uuid PK.
 */
final class BrandingSetting extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'colors', 'fonts', 'white_label',
    ];

    protected $casts = [
        'colors' => 'array',
        'fonts' => 'array',
        'white_label' => 'boolean',
    ];

    /**
     * BRANDING-HIERARCHY-001 — a `platform`-scope row belongs to no tenant.
     *
     * `BelongsToTenant` auto-fills `tenant_id` from the request's context, which is right for every
     * other row in this table and wrong for this one: the product's own mark is not a customer's,
     * and stored under whoever uploaded it the platform layer could only ever answer for them. That
     * is what made the documented client → agency → CampaignsHub fallback unreachable.
     *
     * Cleared HERE rather than by weakening the trait — the auto-fill is the backbone of tenant
     * isolation, and the exception belongs with the one scope that has a reason for it.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (self $model): void {
            if ($model->getAttribute('scope') === 'platform') {
                $model->setAttribute('tenant_id', null);
            }
        });

        self::updating(function (self $model): void {
            if ($model->getAttribute('scope') === 'platform') {
                $model->setAttribute('tenant_id', null);
            }
        });
    }
}
