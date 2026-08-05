<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Models;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order from a connected store.
 *
 * It carries the attribution the store recorded AND what that attribution was resolved to, side by
 * side. Keeping both means a client asking «لماذا نُسبت هذه الطلبية لهذه الحملة؟» is answered with the
 * evidence rather than the conclusion, and a resolver improved later can revisit orders it could not
 * place the first time.
 *
 * `net_revenue` is total minus refunds, and it is what the funnel counts. Counting `total` after a
 * refund has been issued reports revenue the merchant no longer has.
 */
final class CommerceOrder extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'commerce_customer_id', 'provider',
        'external_id', 'reference', 'status', 'payment_status', 'placed_at', 'currency', 'subtotal',
        'shipping_total', 'tax_total', 'discount_total', 'total', 'refunded_total', 'refunded_at',
        'cancelled_at', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'click_id', 'click_id_provider', 'landing_url', 'referrer_url', 'external_campaign_id',
        'unified_campaign_id', 'attribution_method', 'attributed_at', 'is_demo', 'last_synced_at',
    ];

    protected $casts = [
        'placed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'attributed_at' => 'datetime',
        'subtotal' => 'decimal:6',
        'shipping_total' => 'decimal:6',
        'tax_total' => 'decimal:6',
        'discount_total' => 'decimal:6',
        'total' => 'decimal:6',
        'refunded_total' => 'decimal:6',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /** What the merchant actually kept: the order total less anything refunded. */
    public function netRevenue(): float
    {
        if ($this->cancelled_at !== null) {
            return 0.0;
        }

        return max(0.0, (float) $this->total - (float) $this->refunded_total);
    }

    /** @return HasMany<CommerceOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CommerceOrderItem::class, 'commerce_order_id');
    }

    /** @return BelongsTo<CommerceCustomer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CommerceCustomer::class, 'commerce_customer_id');
    }

    /** @return BelongsTo<ExternalCampaign, $this> */
    public function externalCampaign(): BelongsTo
    {
        return $this->belongsTo(ExternalCampaign::class, 'external_campaign_id');
    }

    /** @return BelongsTo<UnifiedCampaign, $this> */
    public function unifiedCampaign(): BelongsTo
    {
        return $this->belongsTo(UnifiedCampaign::class, 'unified_campaign_id');
    }
}
