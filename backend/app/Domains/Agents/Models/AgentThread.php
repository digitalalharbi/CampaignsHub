<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One conversation with one agent about one project. */
final class AgentThread extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'agent_threads';

    protected $fillable = ['tenant_id', 'project_id', 'agent_key', 'title', 'status', 'last_error', 'created_by'];

    /** @return HasMany<AgentMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'thread_id')->orderBy('seq');
    }
}
