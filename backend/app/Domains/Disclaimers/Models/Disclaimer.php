<?php

declare(strict_types=1);

namespace App\Domains\Disclaimers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A scoped override of the system disclaimer defaults. `payload` carries only the sections that
 * differ from the level above it; DisclaimerResolver deep-merges system → org → client → project.
 */
final class Disclaimer extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const SCOPES = ['organization', 'client', 'project'];

    protected $fillable = [
        'tenant_id', 'scope', 'scope_id', 'payload', 'version', 'is_active', 'effective_at', 'updated_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'version' => 'integer',
        'is_active' => 'boolean',
        'effective_at' => 'datetime',
    ];
}
