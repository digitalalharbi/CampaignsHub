<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An ad set (Meta) / ad group (Google, TikTok) — the targeting and budget layer beneath a campaign. */
final class ExternalAdSet extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'external_campaign_id', 'unified_campaign_id', 'provider',
        'external_id', 'name', 'status', 'optimization_goal', 'bid_strategy', 'daily_budget',
        'lifetime_budget', 'currency', 'targeting', 'starts_at', 'ends_at', 'source_type',
        'is_demo', 'last_synced_at',
    ];

    protected $casts = [
        'targeting' => 'array',
        'daily_budget' => 'decimal:4',
        'lifetime_budget' => 'decimal:4',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_demo' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /** @return HasMany<ExternalAd, $this> */
    public function ads(): HasMany
    {
        return $this->hasMany(ExternalAd::class, 'external_ad_set_id');
    }
}
