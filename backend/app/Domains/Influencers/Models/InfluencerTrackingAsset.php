<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one creator's traffic is told apart from another's (INFL-003).
 *
 * The two kinds are NOT equally knowable, and the model says so rather than presenting one number
 * beside the other as though they were:
 *
 * - A **link** is served by this platform. Its click count is measured here, by this application,
 *   and is as real as anything in the product.
 * - A **discount code** is redeemed in the brand's own store, which this platform cannot see. Its
 *   count carries `redemptions_source`, and until somebody supplies it that source is
 *   `awaiting_credentials` — which means the zero is an absence of information, not a result.
 */
final class InfluencerTrackingAsset extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const KINDS = ['link', 'discount_code'];

    /** Where a redemption figure came from. `platform` is reserved for a real store integration. */
    public const REDEMPTION_SOURCES = ['awaiting_credentials', 'manual', 'platform'];

    protected $table = 'influencer_tracking_assets';

    protected $fillable = [
        'tenant_id', 'collaboration_id', 'deliverable_id', 'kind', 'code',
        'destination_url', 'discount_type', 'discount_value',
        'clicks', 'last_clicked_at', 'redemptions', 'redemptions_source', 'redemptions_updated_at',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'clicks' => 'integer',
        'redemptions' => 'integer',
        'discount_value' => 'decimal:2',
        'is_active' => 'boolean',
        'last_clicked_at' => 'datetime',
        'redemptions_updated_at' => 'datetime',
    ];

    /** @return BelongsTo<InfluencerCollaboration, $this> */
    public function collaboration(): BelongsTo
    {
        return $this->belongsTo(InfluencerCollaboration::class, 'collaboration_id');
    }

    /** @return BelongsTo<InfluencerDeliverable, $this> */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(InfluencerDeliverable::class, 'deliverable_id');
    }

    /**
     * True when the number beside this asset is something the platform actually knows.
     *
     * A link always is. A code only is once a person or a store has supplied a figure — and the
     * interface uses this to label the difference instead of showing two zeroes that mean opposite
     * things.
     */
    public function countIsMeasured(): bool
    {
        return $this->kind === 'link' || $this->redemptions_source !== 'awaiting_credentials';
    }
}
