<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * ANALYTICS-DATA-001 — one influencer's dedicated tracking link inside one project.
 *
 * The link is a row of the existing short-link library; this row says whose it is and holds the FIXED UTM it was
 * minted with. `utm_campaign` is unique within the project, so an order tied by it can only be one influencer's.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property ?string $influencer_id
 * @property ?string $collaboration_id
 * @property string $short_link_id
 * @property string $name
 * @property string $handle_slug
 * @property string $utm_source
 * @property string $utm_medium
 * @property string $utm_campaign
 * @property ?string $coupon_code
 * @property bool $is_active
 */
final class InfluencerProjectLink extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'influencer_project_links';

    protected $fillable = [
        'tenant_id', 'project_id', 'influencer_id', 'collaboration_id', 'short_link_id', 'name', 'handle_slug',
        'utm_source', 'utm_medium', 'utm_campaign', 'coupon_code', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];
}
