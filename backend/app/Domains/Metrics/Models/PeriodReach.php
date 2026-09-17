<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * REACH-PERIOD-001 — one provider-deduplicated reach for one entity over one exact window.
 *
 * @property string $external_account_id
 * @property string $provider
 * @property string $grain
 * @property string $external_entity_id
 * @property string|null $external_campaign_id
 * @property string|null $reach
 * @property string|null $frequency
 * @property string|null $impressions
 * @property string $state
 */
final class PeriodReach extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const ACCOUNT = 'account';

    public const CAMPAIGN = 'campaign';

    /** The provider answered with a figure for this entity and window. */
    public const REPORTED = 'reported';

    /** The provider was asked and returned no reach for this entity and window. */
    public const NOT_REPORTED = 'not_reported';

    protected $table = 'period_reach';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'external_account_id', 'provider', 'grain', 'external_entity_id', 'external_campaign_id',
        'date_from', 'date_to', 'reach', 'frequency', 'impressions', 'state', 'fetched_at',
    ];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'fetched_at' => 'datetime'];
    }
}
