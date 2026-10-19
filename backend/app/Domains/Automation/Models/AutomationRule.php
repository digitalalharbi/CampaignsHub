<?php

declare(strict_types=1);

namespace App\Domains\Automation\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** AUTO-RULES-001 — one Owner-defined rule for one project. */
final class AutomationRule extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    public const MODES = ['monitor', 'recommend', 'auto'];

    public const LEVELS = ['campaign', 'ad_set', 'ad'];

    protected $table = 'automation_rules';

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'enabled', 'mode', 'level', 'scope', 'logic', 'conditions', 'action',
        'schedule', 'cooldown_hours', 'min_spend', 'last_run_at', 'next_run_at', 'last_result', 'created_by', 'updated_by', 'origin', 'drafted_by_agent',
    ];

    protected $casts = [
        'enabled' => 'boolean', 'scope' => 'array', 'conditions' => 'array', 'action' => 'array', 'schedule' => 'array',
        'cooldown_hours' => 'integer', 'min_spend' => 'float', 'last_run_at' => 'datetime', 'next_run_at' => 'datetime',
    ];

    /** @return HasMany<AutomationRuleRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRuleRun::class, 'rule_id');
    }
}
