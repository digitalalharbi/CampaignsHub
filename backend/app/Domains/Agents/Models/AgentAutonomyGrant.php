<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** An owner's standing permission for one narrow action class on one project; active while not revoked. */
final class AgentAutonomyGrant extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'agent_autonomy_grants';

    protected $fillable = ['tenant_id', 'project_id', 'action_class', 'granted_by', 'granted_at', 'revoked_at', 'revoked_by', 'ladder_at_grant'];

    protected $casts = ['granted_at' => 'datetime', 'revoked_at' => 'datetime', 'ladder_at_grant' => 'array'];
}
