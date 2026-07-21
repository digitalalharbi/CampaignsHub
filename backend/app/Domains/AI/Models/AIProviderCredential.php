<?php

declare(strict_types=1);

namespace App\Domains\AI\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored AI provider key (BYOK). The `secret` is encrypted at rest and hidden from serialization;
 * the API only ever exposes `last_four`. Isolation: always tenant-scoped, optionally narrowed to a
 * client workspace and/or project.
 */
final class AIProviderCredential extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'ai_provider_credentials';

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'project_id', 'provider', 'credential_scope',
        'encrypted_secret', 'last_four', 'organization_id', 'project_external_id', 'status',
        'monthly_budget', 'monthly_token_limit', 'allowed_models', 'allowed_features',
        'last_health_check_at', 'last_used_at', 'rotated_at', 'created_by',
    ];

    protected $casts = [
        'encrypted_secret' => 'encrypted', // encrypted at rest via APP_KEY
        'allowed_models' => 'array',
        'allowed_features' => 'array',
        'monthly_budget' => 'decimal:2',
        'last_health_check_at' => 'datetime',
        'last_used_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    // Never serialise the secret.
    protected $hidden = ['encrypted_secret'];

    /** Store a secret: encrypt it and keep a safe masked hint. */
    public function setSecret(string $plain): void
    {
        $this->encrypted_secret = $plain;
        $this->last_four = substr($plain, -4);
    }

    /** Decrypt for server-side use only (never returned to clients). */
    public function revealSecret(): string
    {
        return (string) $this->encrypted_secret;
    }

    public function maskedKey(): string
    {
        return $this->last_four !== null ? '••••'.$this->last_four : '••••';
    }
}
