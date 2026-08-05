<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer of a connected store.
 *
 * This is the MERCHANT's customer, not ours, and the row exists only because a client report says
 * «عميل عائد» and «أفضل العملاء». It is tenant-scoped like everything else and never leaves the tenant
 * that synced it.
 */
final class CommerceCustomer extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'provider', 'external_id', 'name', 'email',
        'phone', 'city', 'country', 'orders_count', 'total_spent', 'first_seen_at', 'is_demo',
        'last_synced_at',
    ];

    protected $casts = [
        'orders_count' => 'integer',
        'total_spent' => 'decimal:6',
        'first_seen_at' => 'datetime',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
