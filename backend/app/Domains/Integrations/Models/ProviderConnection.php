<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A live provider connection (OAuth/session). Discovers external accounts. */
final class ProviderConnection extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'credential_id', 'provider', 'connection_name', 'scope',
        'external_owner_id', 'scopes', 'status', 'token_expires_at', 'last_health_check_at',
        'last_successful_sync_at', 'last_error', 'created_by',
        /*
         * GADS-STALE-PICKER-001 — whether the LAST discovery produced a list, and why not.
         *
         * Three facts rather than one flag: «never attempted», «attempted and refused» and «answered»
         * send an operator to three different places, and the account rows cannot say which it was —
         * they survive a refusal by design, which is exactly why they were being offered as current.
         */
        'last_discovery_attempted_at', 'last_discovery_succeeded_at', 'discovery_blocked_reason',
    ];

    protected $casts = [
        'scopes' => 'array',
        'token_expires_at' => 'datetime',
        'last_health_check_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'last_discovery_attempted_at' => 'datetime',
        'last_discovery_succeeded_at' => 'datetime',
    ];

    /** @return HasMany<ExternalAccount, $this> */
    public function externalAccounts(): HasMany
    {
        return $this->hasMany(ExternalAccount::class);
    }
}
