<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use App\Support\Concerns\NormalisesPhoneNumbers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A creator on the agency's roster (INFL-001).
 *
 * Outlives any one campaign: a creator worked with last year is still here, with their audience
 * figures and the history of what they have done. What they are PAID and who they worked FOR lives
 * on the collaboration, not here — the same creator costs different money for different clients.
 */
final class Influencer extends Model
{
    use NormalisesPhoneNumbers;

    /** PHONE-001 — normalised to E.164 on save, from every caller. See the trait. */
    protected array $phoneColumns = ['contact_phone'];

    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'handle', 'primary_platform', 'profile_url', 'followers',
        'engagement_rate', 'tier', 'categories', 'country', 'language',
        'contact_email', 'contact_phone', 'status', 'internal_notes', 'owner_id',
    ];

    /*
     * `user_id` is absent from $fillable ON PURPOSE (INFL-002).
     *
     * That column decides whose collaborations, whose fee and whose brief a person sees when they
     * sign in as a creator. Listing it above would make it a form field — a roster PATCH carrying
     * `user_id` would hand one creator another creator's earnings. It is written only by
     * LinkCreatorAccount, which checks the account is free first.
     */

    protected $casts = [
        'categories' => 'array',
        'followers' => 'integer',
        'engagement_rate' => 'decimal:2',
    ];

    /** @return HasMany<InfluencerCollaboration, $this> */
    public function collaborations(): HasMany
    {
        return $this->hasMany(InfluencerCollaboration::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The creator's own login, if they have been given portal access (INFL-002).
     *
     * Distinct from `owner()`, which is the agency person responsible for the relationship. Confusing
     * the two would let a creator read the account manager's view of themselves.
     *
     * @return BelongsTo<User, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function hasPortalAccess(): bool
    {
        return $this->user_id !== null;
    }
}
