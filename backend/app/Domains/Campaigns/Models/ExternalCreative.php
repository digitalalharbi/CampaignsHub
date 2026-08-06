<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An ad creative synced from a platform. Project/tenant scoped. Thumbnails are never fabricated. */
final class ExternalCreative extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'campaign_id', 'external_campaign_id', 'external_ad_set_id', 'provider',
        'external_creative_id', 'external_ad_id', 'name', 'client_display_name', 'format',
        'thumbnail_url', 'preview_url', 'asset_url', 'video_url', 'destination_url', 'status', 'source_type',
        'width', 'height', 'aspect_ratio', 'duration_seconds', 'file_size', 'file_hash',
        'body', 'headline', 'description', 'cta',
        'first_seen_at', 'last_active_at', 'source_updated_at', 'asset_expires_at', 'raw', 'cards',
        'creative_group_id', 'last_synced_at', 'is_demo',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_active_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'asset_expires_at' => 'datetime',
        'raw' => 'array',
        /*
         * A carousel's cards, or null.
         *
         * `null` is «the provider sent no card breakdown»; `[]` is «it sent one and it was empty».
         * Two different sentences, and collapsing them is how a five-card creative renders as one
         * picture with nothing on screen admitting it.
         */
        'cards' => 'array',
        'is_demo' => 'boolean',
    ];

    /**
     * Whether the platform's own asset link has run out.
     *
     * Platform preview URLs expire, often within hours. A library that renders one regardless shows a
     * broken frame and blames the creative; knowing it expired is what lets the page say «needs a
     * refresh» instead — and lets the sync know which rows to go back for.
     */
    public function assetExpired(): bool
    {
        return $this->asset_expires_at !== null && $this->asset_expires_at->isPast();
    }

    /** @return BelongsTo<CreativeGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CreativeGroup::class, 'creative_group_id');
    }
}
