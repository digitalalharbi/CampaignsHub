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
        'external_id', 'parent_external_id', 'parent_name', 'name', 'currency', 'timezone', 'status', 'metadata',
        'discovered_at', 'access_lost_at', 'last_synced_at', 'last_structure_synced_at',
        'last_sync_attempt_at', 'last_sync_error_category', 'next_sync_at',
        'management_state', 'management_state_changed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        // ORCH-100 — three different facts, three different columns. `discovered_at` is when the
        // provider told us this account exists; `last_synced_at` is when we last fetched its data,
        // and stays null until that really happens; `access_lost_at` is when the provider stopped
        // letting us reach it, which stops the sync without deleting the history.
        'discovered_at' => 'datetime',
        'access_lost_at' => 'datetime',
        'last_synced_at' => 'datetime',
        // STRUCT-001: structure and metrics run on different clocks, so they have different columns.
        'last_structure_synced_at' => 'datetime',
        /*
         * RUNTIME-100 §30 — the three questions `last_synced_at` alone could not answer.
         *
         * «We tried an hour ago and it failed», «nobody has ever tried» and «we succeeded and are due
         * again at 03:30» all rendered as the same absent or stale date, so a broken integration and a
         * brand-new one were the same pixel on every screen.
         */
        'last_sync_attempt_at' => 'datetime',
        'next_sync_at' => 'datetime',
        /*
         * COMMAND-CENTER §7 — the customer's decision, and when they made it.
         *
         * NULL is «discovered»: the provider returned it and nobody has said anything. `assigned` is
         * never a value here — that is the binding's answer, and a second copy of it would drift.
         */
        'management_state_changed_at' => 'datetime',
    ];

    /** @return BelongsTo<ProviderConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }
}
