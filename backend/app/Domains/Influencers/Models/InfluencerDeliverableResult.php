<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one post actually did (INFL-003).
 *
 * Attached to the DELIVERABLE rather than the collaboration, because «which post worked» is the only
 * question that changes what you commission next time, and a campaign-level total cannot answer it.
 *
 * `source` is part of the identity: a hand-entered figure and a platform-synced one sit side by side
 * and stay distinguishable, so connecting a platform later does not silently overwrite what a person
 * typed — and nobody has to guess afterwards which of the two they are looking at.
 */
final class InfluencerDeliverableResult extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const SOURCES = ['manual', 'platform'];

    protected $table = 'influencer_deliverable_results';

    protected $fillable = [
        'tenant_id', 'deliverable_id', 'source',
        'impressions', 'reach', 'engagements', 'clicks', 'conversions', 'revenue', 'currency',
        'measured_at', 'recorded_by', 'note',
    ];

    protected $casts = [
        'impressions' => 'integer',
        'reach' => 'integer',
        'engagements' => 'integer',
        'clicks' => 'integer',
        'conversions' => 'integer',
        'revenue' => 'decimal:2',
        'measured_at' => 'datetime',
    ];

    /** @return BelongsTo<InfluencerDeliverable, $this> */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(InfluencerDeliverable::class, 'deliverable_id');
    }

    /**
     * Engagement rate against reach, or null when either side is unknown.
     *
     * Null rather than zero on purpose: a rate of 0% is a post nobody engaged with, and «we do not
     * know the reach» is a different statement that must not be able to look like it.
     */
    public function engagementRate(): ?float
    {
        if ($this->reach === null || $this->reach === 0 || $this->engagements === null) {
            return null;
        }

        return round(($this->engagements / $this->reach) * 100, 2);
    }
}
