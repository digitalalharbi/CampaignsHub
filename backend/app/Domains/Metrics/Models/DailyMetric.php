<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single normalized daily metric value for a campaign. Tenant + project scoped. Preserves the
 * original currency/amount/timezone alongside the project-normalized value; the natural-key unique
 * index (account, campaign, metric, date, attribution window) makes ingestion an idempotent upsert.
 */
final class DailyMetric extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    /** Columns forming the idempotency key (one row per combination). */
    public const NATURAL_KEY = [
        'external_account_id', 'external_campaign_id', 'metric_key', 'metric_date', 'attribution_window',
    ];

    protected $fillable = [
        'tenant_id', 'project_id', 'connection_id', 'external_account_id', 'external_campaign_id',
        'unified_campaign_id', 'provider', 'metric_key', 'metric_date', 'value',
        'original_currency', 'project_currency', 'original_amount', 'converted_amount', 'exchange_rate',
        'rate_date', 'rate_source',
        'original_timezone', 'project_timezone', 'attribution_window', 'source_type',
        'data_freshness_at', 'sync_run_id', 'raw',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'value' => 'decimal:6',
        'original_amount' => 'decimal:6',
        'converted_amount' => 'decimal:6',
        'exchange_rate' => 'decimal:12',
        'rate_date' => 'date',
        'data_freshness_at' => 'datetime',
        'raw' => 'array',
    ];

    /** @return BelongsTo<MetricSyncRun, $this> */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(MetricSyncRun::class, 'sync_run_id');
    }

    /** @return BelongsTo<MetricDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(MetricDefinition::class, 'metric_key', 'key');
    }
}
