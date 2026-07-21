<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit record. Rows are written once and never updated or deleted through the app.
 */
final class AuditLog extends Model
{
    use HasUuidKey;

    public const UPDATED_AT = null; // no updated_at column — records are immutable

    protected $fillable = [
        'tenant_id', 'user_id', 'action', 'entity_type', 'entity_id',
        'before', 'after', 'ip_address', 'user_agent', 'correlation_id', 'reason',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
    ];
}
