<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An external account discovered from a provider connection (ad account, pixel, store, …). */
final class ExternalAccount extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'provider_connection_id', 'provider', 'account_type',
        'external_id', 'parent_external_id', 'name', 'currency', 'timezone', 'status', 'metadata',
        'last_synced_at', 'last_structure_synced_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
        // STRUCT-001: structure and metrics run on different clocks, so they have different columns.
        'last_structure_synced_at' => 'datetime',
    ];

    /** @return BelongsTo<ProviderConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }
}
