<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * An alert rule: a condition on a project's metrics that, when met, raises a notification. Evaluation
 * respects the rule's cooldown + quiet hours; demo rules only ever raise demo notifications.
 */
final class AlertRule extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    public const TYPES = [
        'budget_exceeded', 'budget_nearing', 'budget_pacing_fast', 'budget_pacing_slow',
        'cpa_increased', 'roas_decreased', 'spend_no_conversions', 'no_spend', 'no_results',
        'campaign_ending_soon', 'stale_data', 'sync_failed', 'token_expiring', 'token_expired',
    ];

    protected $fillable = [
        'tenant_id', 'project_id', 'unified_campaign_id', 'connection_id', 'name', 'rule_type', 'metric',
        'operator', 'threshold', 'evaluation_window', 'severity', 'cooldown', 'quiet_hours', 'channels',
        'is_active', 'last_triggered_at', 'is_demo', 'created_by',
    ];

    protected $casts = [
        'threshold' => 'float',
        'evaluation_window' => 'integer',
        'cooldown' => 'integer',
        'quiet_hours' => 'array',
        'channels' => 'array',
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];
}
