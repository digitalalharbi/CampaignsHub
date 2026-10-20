<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Touchpoints;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * TOUCHPOINT-EVIDENCE-001 — one recorded marketing interaction. See the migration for what it is and is not.
 *
 * `is_demo` is not fillable, as on the metric models: a demo flag that could be mass-assigned is one a payload
 * could clear.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $channel
 * @property bool $is_paid
 * @property string $consent_state
 * @property ?string $visitor_key
 * @property ?string $click_id
 * @property ?string $click_id_provider
 * @property ?string $tracking_token
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 */
final class MarketingTouchpoint extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const GRANTED = 'granted';

    public const NOT_REQUIRED = 'not_required';

    public const DENIED = 'denied';

    public const UNKNOWN = 'unknown';

    protected $table = 'marketing_touchpoints';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'project_id', 'source_type', 'source_ref', 'dedupe_key', 'channel', 'is_paid',
        'external_campaign_id', 'smart_link_id', 'influencer_asset_id',
        'click_id', 'click_id_provider', 'tracking_token',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'consent_state', 'visitor_key', 'occurred_at', 'recorded_at',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_demo' => 'boolean',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];
}
