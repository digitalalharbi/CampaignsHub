<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** OAuth/API credential — payload encrypted at rest; never returned by the API. */
final class IntegrationCredential extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'provider', 'credential_scope',
        'credential_type', 'encrypted_payload', 'status', 'expires_at', 'last_rotated_at', 'created_by',
    ];

    protected $casts = [
        'encrypted_payload' => 'encrypted',
        'expires_at' => 'datetime',
        'last_rotated_at' => 'datetime',
    ];

    protected $hidden = ['encrypted_payload'];

    public function setPayload(string $plain): void
    {
        $this->encrypted_payload = $plain;
    }

    public function revealPayload(): string
    {
        return (string) $this->encrypted_payload;
    }
}
