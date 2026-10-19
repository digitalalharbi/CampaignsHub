<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/** One turn: the person's words, the model's blocks, or the tool results sent back. Append-only. */
final class AgentMessage extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const UPDATED_AT = null;

    protected $table = 'agent_messages';

    protected $fillable = ['tenant_id', 'thread_id', 'role', 'content', 'text', 'tool_calls', 'usage', 'model'];

    protected $casts = ['content' => 'array', 'tool_calls' => 'array', 'usage' => 'array', 'created_at' => 'datetime'];
}
