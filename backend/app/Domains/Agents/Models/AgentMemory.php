<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * AGENTS-MEMORY-001 — one standing preference of ONE person in ONE workspace («اختصر دائمًا»).
 *
 * Saved only when the person asks («تذكّر …») or accepts a suggested chip; scrubbed of anything secret-like before it is stored
 * (`MemoryScrubber`); readable, editable and deletable only by its owner.
 */
final class AgentMemory extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'agent_memories';

    protected $fillable = ['tenant_id', 'user_id', 'text', 'source'];
}
