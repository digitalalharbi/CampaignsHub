<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** A project's switch for one scheduled routine, and what its last run did. */
final class AgentRoutine extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'agent_routines';

    protected $fillable = ['tenant_id', 'project_id', 'routine', 'enabled', 'last_run_at', 'last_thread_id', 'last_outcome', 'updated_by'];

    protected $casts = ['enabled' => 'boolean', 'last_run_at' => 'datetime'];
}
