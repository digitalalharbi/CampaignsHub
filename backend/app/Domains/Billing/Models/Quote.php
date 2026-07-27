<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A priced offer to a client (draft → sent → approved → rejected/expired). Approving a quote generates an
 * Invoice. Tenant-scoped; the bigint PK is auto-incrementing (no HasUuidKey).
 */
final class Quote extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'request_id', 'project_id', 'number', 'currency',
        'subtotal', 'tax', 'discount', 'total', 'status', 'valid_until', 'notes', 'created_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'valid_until' => 'date',
    ];

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
