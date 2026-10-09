<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * GA4-INTEGRATION-001 — a measured day for one property.
 *
 * Its own model rather than a `metric_key` inside `DailyMetric`, for the reason the table is its own:
 * a query that sums advertising days must not be able to pick these up, and the surest version of
 * that rule is that it cannot name them.
 *
 * `BelongsToTenant` because every read of this table is a tenant's read. The sync writes through the
 * query builder — where, as `CreativeResultAttribution` learned the hard way, no global scope applies
 * and the tenant column has to be stated — so the scope here protects the READERS.
 */
final class MeasurementDailyMetric extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'measurement_daily_metrics';

    protected $fillable = [
        'tenant_id', 'project_id', 'external_account_id', 'property_id',
        'metric_date', 'metric_key', 'value', 'currency', 'timezone',
    ];

    protected $casts = [
        'metric_date' => 'date',
        /*
         * A string, not a float. `decimal:6` keeps the stored precision as text all the way to the
         * reader, where a float would turn 1234.567890 into something that no longer matches the
         * client's own Analytics screen in the last place.
         */
        'value' => 'decimal:6',
    ];
}
