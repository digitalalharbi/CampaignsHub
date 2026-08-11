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
        /*
         * COMMERCE-FX-001 — the amount columns are in `currency`, the project's reporting currency.
         * What the merchant charged, and in what, is kept beside them and never overwritten.
         */
        'original_currency', 'original_subtotal', 'original_shipping_total', 'original_tax_total',
        'original_discount_total', 'original_total', 'original_refunded_total',
        'exchange_rate', 'rate_date', 'rate_source',
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
        'original_subtotal' => 'decimal:6',
        'original_shipping_total' => 'decimal:6',
        'original_tax_total' => 'decimal:6',
        'original_discount_total' => 'decimal:6',
        'original_total' => 'decimal:6',
        'original_refunded_total' => 'decimal:6',
        'exchange_rate' => 'decimal:12',
        'rate_date' => 'date',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * What the merchant actually kept, in the project's reporting currency — or NOTHING KNOWABLE.
     *
     * Null, not 0, when the conversion was withheld (COMMERCE-FX-001). The return type is nullable so
     * that every caller has to decide what to do about it: the previous `float` would have turned a
     * withheld order into «this sale earned nothing», which is the single most misleading figure this
     * product can print. Callers coalesce to 0 for the SUM and count the withheld orders separately,
     * so a short total is stated rather than merely being short.
     */
    public function netRevenue(): ?float
    {
        if ($this->cancelled_at !== null) {
            return 0.0;
        }

        if ($this->moneyWithheld()) {
            return null;
        }

        return max(0.0, (float) $this->total - (float) $this->refunded_total);
    }

    /**
     * The provider stated a total and no trustworthy rate existed for the day it was placed.
     *
     * Distinguished from an order the provider never priced at all — which is a gap in the payload,
     * not in our rates — by the original amount that survives beside the withheld one.
     */
    public function moneyWithheld(): bool
    {
        return $this->total === null && $this->original_total !== null;
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
