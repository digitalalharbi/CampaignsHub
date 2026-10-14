<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * SALLA-INSTALL-001 — a store installed from its platform's side, waiting for a workspace to claim it.
 *
 * Not tenant-scoped on purpose: until it is claimed it belongs to nobody, and the claim code — kept
 * only as a hash — is the one thing that can attach it to a workspace.
 *
 * @property string $id
 * @property string $provider
 * @property string $merchant_id
 * @property ?string $store_name
 * @property ?string $store_domain
 * @property ?string $store_currency
 * @property array<string,mixed> $tokens
 * @property ?string $claim_code_hash
 * @property Carbon $expires_at
 * @property ?Carbon $claimed_at
 * @property ?string $claimed_tenant_id
 * @property ?string $provider_connection_id
 */
final class PendingStoreInstall extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['tokens', 'claim_code_hash'];

    protected $casts = [
        'tokens' => 'encrypted:array',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
    ];
}
