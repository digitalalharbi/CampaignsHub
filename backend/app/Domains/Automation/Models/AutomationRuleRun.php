<?php

declare(strict_types=1);

namespace App\Domains\Automation\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** One evaluation of a rule: how many objects it read, matched and acted on, and how it ended. */
final class AutomationRuleRun extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public $timestamps = false;

    protected $table = 'automation_rule_runs';

    protected $fillable = ['tenant_id', 'rule_id', 'dry_run', 'trigger', 'evaluated', 'matched', 'acted', 'skipped', 'failed', 'status', 'error', 'started_at', 'finished_at'];

    protected $casts = ['dry_run' => 'boolean', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
}
