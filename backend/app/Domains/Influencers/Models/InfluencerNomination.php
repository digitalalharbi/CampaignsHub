<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A creator put forward for a campaign, and the answer they got (INFL-003).
 *
 * A collaboration is what exists AFTER somebody said yes. This is the decision itself, and it is
 * kept whichever way the decision went: a rejected nomination with its reason is what stops the same
 * creator being proposed again next quarter by somebody who was not in the room.
 */
final class InfluencerNomination extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** proposed → approved | rejected | withdrawn. `approved` is the only one that may become work. */
    public const STATUSES = ['proposed', 'approved', 'rejected', 'withdrawn'];

    protected $table = 'influencer_nominations';

    protected $fillable = [
        'tenant_id', 'influencer_id', 'campaign_id', 'client_workspace_id',
        'status', 'proposed_fee', 'currency', 'rationale',
        'proposed_by', 'proposed_at', 'decided_by', 'decided_at', 'decision_note',
        'collaboration_id',
    ];

    protected $casts = [
        'proposed_at' => 'datetime',
        'decided_at' => 'datetime',
        'proposed_fee' => 'decimal:2',
    ];

    /** @return BelongsTo<Influencer, $this> */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class, 'influencer_id');
    }

    /** @return BelongsTo<InfluencerCollaboration, $this> */
    public function collaboration(): BelongsTo
    {
        return $this->belongsTo(InfluencerCollaboration::class, 'collaboration_id');
    }

    /** Only an approved nomination that has not already become work may be turned into a collaboration. */
    public function isConvertible(): bool
    {
        return $this->status === 'approved' && $this->collaboration_id === null;
    }
}
