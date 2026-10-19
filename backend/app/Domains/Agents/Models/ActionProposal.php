<?php

declare(strict_types=1);

namespace App\Domains\Agents\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's (or a person's) request to change an advertising object, waiting for a decision.
 *
 * Writing a row does nothing to any platform. See `ProposalService` for the only path that moves it.
 */
final class ActionProposal extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const EXECUTING = 'executing';

    public const EXECUTED = 'executed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const BLOCKED = 'blocked';

    public const EXPIRED = 'expired';

    /** Statuses in which an identical proposal is the same request, not a new one. */
    public const OPEN = [self::PENDING, self::APPROVED, self::EXECUTING];

    protected $table = 'action_proposals';

    protected $fillable = [
        'tenant_id', 'project_id', 'client_workspace_id', 'agent_key', 'provider', 'external_account_id',
        'target_type', 'target_external_id', 'action', 'payload', 'currency', 'reason', 'evidence', 'status',
        'violations', 'idempotency_key', 'requested_by', 'decided_by', 'decided_at', 'decision_note',
        'expires_at', 'executed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'violations' => 'array',
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    /** @return HasMany<ActionLog, $this> */
    public function log(): HasMany
    {
        return $this->hasMany(ActionLog::class, 'proposal_id')->orderBy('created_at');
    }
}
