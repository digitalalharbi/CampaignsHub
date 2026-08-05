<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** A product in a connected Salla or Zid store. Tenant- and project-scoped like everything synced. */
final class CommerceProduct extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider', 'external_id', 'name', 'sku',
        'status', 'price', 'currency', 'quantity', 'category', 'url', 'image_url', 'is_demo',
        'last_synced_at',
    ];

    protected $casts = [
        'price' => 'decimal:6',
        'quantity' => 'integer',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
