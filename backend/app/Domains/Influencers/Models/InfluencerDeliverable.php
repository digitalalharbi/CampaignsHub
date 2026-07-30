<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One piece of content owed under a collaboration (INFL-001).
 *
 * Separate rows rather than a count, because "two of three posts are live, the third is late" is the
 * question everyone actually asks, and a single status on the collaboration cannot answer it.
 */
final class InfluencerDeliverable extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    protected $table = 'influencer_deliverables';

    protected $fillable = [
        'tenant_id', 'collaboration_id', 'type', 'platform', 'status', 'due_on',
        'submitted_url', 'submitted_at', 'approved_at', 'published_at', 'feedback',
    ];

    protected $casts = [
        'due_on' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<InfluencerCollaboration, $this> */
    public function collaboration(): BelongsTo
    {
        return $this->belongsTo(InfluencerCollaboration::class, 'collaboration_id');
    }

    /** Overdue means "owed, past its date, and not yet delivered" — a published post is never late. */
    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->due_on->isPast()
            && ! in_array($this->status, ['approved', 'published', 'cancelled'], true);
    }
}
