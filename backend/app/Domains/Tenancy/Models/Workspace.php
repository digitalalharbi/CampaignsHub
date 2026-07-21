<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A workspace groups a tenant's operational data (clients, campaigns, ...). Tenant-isolated.
 */
final class Workspace extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'settings', 'tenant_id'];

    protected $casts = ['settings' => 'array'];
}
