<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
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
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'handle', 'primary_platform', 'profile_url', 'followers',
        'engagement_rate', 'tier', 'categories', 'country', 'language',
        'contact_email', 'contact_phone', 'status', 'internal_notes', 'owner_id',
    ];

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
}
