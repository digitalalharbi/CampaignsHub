<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an order. Not project-scoped: it reaches its project through its order. */
final class CommerceOrderItem extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'commerce_order_id', 'commerce_product_id', 'external_id',
        'product_external_id', 'sku', 'name', 'quantity', 'unit_price', 'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:6',
        'total' => 'decimal:6',
    ];

    /** @return BelongsTo<CommerceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class, 'commerce_order_id');
    }
}
