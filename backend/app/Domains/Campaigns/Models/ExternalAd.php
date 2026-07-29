<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A single ad inside an ad set — the creative-carrying leaf of the platform hierarchy. */
final class ExternalAd extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_ad_set_id', 'external_campaign_id', 'unified_campaign_id',
        'creative_id', 'provider', 'external_id', 'name', 'status', 'review_status',
        'destination_url', 'source_type', 'is_demo', 'last_synced_at',
    ];

    protected $casts = [
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /** @return BelongsTo<ExternalAdSet, $this> */
    public function adSet(): BelongsTo
    {
        return $this->belongsTo(ExternalAdSet::class, 'external_ad_set_id');
    }

    /** @return BelongsTo<ExternalCreative, $this> */
    public function creative(): BelongsTo
    {
        return $this->belongsTo(ExternalCreative::class, 'creative_id');
    }
}
