<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** An ad creative synced from a platform. Project/tenant scoped. Thumbnails are never fabricated. */
final class ExternalCreative extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'campaign_id', 'external_campaign_id', 'provider',
        'external_creative_id', 'external_ad_id', 'name', 'client_display_name', 'format',
        'thumbnail_url', 'preview_url', 'destination_url', 'status', 'source_type',
        'last_synced_at', 'is_demo',
    ];

    protected $casts = ['last_synced_at' => 'datetime', 'is_demo' => 'boolean'];
}
