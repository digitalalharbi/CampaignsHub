<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An issued bill. amount_paid tracks partial settlement; status moves draft → issued → partially_paid → paid
 * (or void/refunded). A verified webhook is the ONLY thing that drives it to paid. Tenant-scoped; bigint PK.
 */
final class Invoice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'quote_id', 'number', 'currency',
        'subtotal', 'tax', 'discount', 'total', 'line_items', 'amount_paid', 'status',
        'due_date', 'issued_at', 'paid_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'line_items' => 'array',
        'amount_paid' => 'decimal:2',
        'due_date' => 'date',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
