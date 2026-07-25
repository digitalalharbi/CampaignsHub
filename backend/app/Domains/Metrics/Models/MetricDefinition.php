<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Global metric catalogue entry. Describes what a metric is and how it aggregates so that
 * daily_metrics rows and the aggregation endpoints stay consistent. Not tenant-scoped.
 */
final class MetricDefinition extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'key', 'name', 'description', 'unit', 'value_type',
        'default_aggregation', 'is_currency', 'is_additive',
    ];

    protected $casts = [
        'is_currency' => 'boolean',
        'is_additive' => 'boolean',
    ];
}
