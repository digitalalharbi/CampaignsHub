<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One agreement: this creator, for this client, for this money (INFL-001).
 *
 * `client_workspace_id` is what the existing client-scope ceiling narrows through, so an account
 * manager confined to three clients sees three clients' collaborations and no others — with no
 * second isolation mechanism to keep in step with the first.
 *
 * `agreed_fee` is billed to the client; `influencer_fee` is paid to the creator. Neither is ever
 * client-facing on its own, and the margin between them is never sent to a client surface at all.
 */
final class InfluencerCollaboration extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $table = 'influencer_collaborations';

    protected $fillable = [
        'tenant_id', 'influencer_id', 'client_workspace_id', 'campaign_id',
        'title', 'status', 'currency', 'agreed_fee', 'influencer_fee',
        'starts_on', 'ends_on', 'brief', 'internal_notes',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'agreed_fee' => 'decimal:2',
        'influencer_fee' => 'decimal:2',
        'terms_sent_at' => 'datetime',
        'creator_responded_at' => 'datetime',
    ];

    /*
     * The agreement fields are absent from $fillable ON PURPOSE (INFL-002). `terms_sent_at` is the
     * gate that decides whether a creator can see this row at all, and `creator_decision` is that
     * person's answer. Neither is a field on the agency's edit form: one is set by sending terms,
     * the other only by the creator themselves.
     */

    /**
     * Has an offer actually been made? (INFL-002)
     *
     * This is the creator's visibility gate, and it is a timestamp rather than a status value
     * because a status is whatever the last form set it to. "Did we offer this?" needs an answer
     * that a dropdown cannot change — otherwise a fee still being argued about internally shows up
     * on the creator's screen as an offer.
     */
    public function isOfferedToCreator(): bool
    {
        return $this->terms_sent_at !== null;
    }

    public function isAcceptedByCreator(): bool
    {
        return $this->creator_decision === 'accepted';
    }

    /** @return BelongsTo<Influencer, $this> */
    public function influencer(): BelongsTo
    {
        return $this->belongsTo(Influencer::class);
    }

    /** @return BelongsTo<ClientWorkspace, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class, 'client_workspace_id');
    }

    /** @return BelongsTo<UnifiedCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(UnifiedCampaign::class, 'campaign_id');
    }

    /** @return HasMany<InfluencerDeliverable, $this> */
    public function deliverables(): HasMany
    {
        return $this->hasMany(InfluencerDeliverable::class, 'collaboration_id');
    }

    /**
     * What the agency keeps. Null unless BOTH sides are known — a margin derived from one figure
     * would be a guess presented as a number.
     */
    public function margin(): ?string
    {
        if ($this->agreed_fee === null || $this->influencer_fee === null) {
            return null;
        }

        return bcsub((string) $this->agreed_fee, (string) $this->influencer_fee, 2);
    }
}
