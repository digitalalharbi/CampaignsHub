<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** A client's goal for one KPI on one platform (or all of them), with the conditions for judging it. */
final class ClientAgentRule extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** The KPIs a rule may name — each is a figure `MetricsAggregator::derive()` already computes. */
    public const KPIS = ['cpa', 'roas', 'cpl', 'cpi', 'cpc', 'cpm', 'ctr', 'conversion_rate'];

    protected $table = 'client_agent_rules';

    protected $fillable = [
        'tenant_id', 'project_id', 'platform', 'kpi', 'direction', 'target_value', 'min_spend_to_judge',
        'special_days', 'forbidden_words', 'notes', 'created_by',
    ];

    protected $casts = [
        'target_value' => 'float',
        'min_spend_to_judge' => 'float',
        'special_days' => 'array',
        'forbidden_words' => 'array',
    ];
}
