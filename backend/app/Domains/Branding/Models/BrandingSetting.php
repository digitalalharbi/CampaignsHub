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
}
