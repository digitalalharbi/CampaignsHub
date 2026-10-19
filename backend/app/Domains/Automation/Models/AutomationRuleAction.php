<?php

declare(strict_types=1);

namespace App\Domains\Automation\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** What a rule did — or declined to do, and why — to one object in one run. Append-only. */
final class AutomationRuleAction extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    /** Statuses that count as «acted» for the cooldown: the same object is not touched again inside it. */
    public const ACTED = ['notified', 'proposed', 'executed', 'failed', 'blocked'];

    protected $table = 'automation_rule_actions';

    protected $fillable = [
        'tenant_id', 'rule_id', 'run_id', 'project_id', 'level', 'provider', 'external_account_id', 'target_external_id',
        'target_name', 'action', 'status', 'metrics', 'before', 'after', 'proposal_id', 'note',
    ];

    protected $casts = ['metrics' => 'array', 'before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
}
