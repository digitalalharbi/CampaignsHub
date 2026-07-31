<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a subscription invoice (SUBINV-001).
 *
 * Rows rather than a JSON blob on the invoice, because an invoice is read line by line by whoever is
 * reconciling it — and because a discount that cannot be attributed to a line is a discount nobody
 * can explain.
 */
final class SubscriptionInvoiceLine extends Model
{
    use HasUuidKey;

    protected $table = 'subscription_invoice_lines';

    protected $fillable = [
        'subscription_invoice_id', 'description', 'plan_code', 'period_label',
        'quantity', 'unit_price', 'discount', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /** @return BelongsTo<SubscriptionInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class, 'subscription_invoice_id');
    }
}
