<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The same asset, running on more than one platform (§15.8).
 *
 * An agency uploads one video to Snapchat, TikTok and Meta and gets three creative ids back. Read as
 * three rows it looks like three pieces of content each with a third of the budget; read as one it is
 * the actual unit somebody produced and is deciding about.
 *
 * ## The methods, in order of how much they prove
 *
 *   - `file_hash` — the same bytes. Evidence.
 *   - `thumbnail_fingerprint` — the same frame. Strong, not certain: two cuts of one shoot can share a
 *     poster.
 *   - `confirmed` — an automatic match a person agreed with.
 *   - `manual` — a person's own judgement, with no automatic match behind it.
 *
 * A filename is deliberately not among them. `hero-video-final.mp4` is what half an agency's assets
 * are called, and §15.8 forbids finalising a merge on that alone — so it is not a value this column
 * can hold, rather than a rule written down somewhere and enforced nowhere.
 */
final class CreativeGroup extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** Ordered by how much each one proves — see the class note. */
    public const METHODS = ['file_hash', 'thumbnail_fingerprint', 'confirmed', 'manual'];

    /** Methods a machine may apply on its own. Anything else needs a person. */
    public const AUTOMATIC_METHODS = ['file_hash', 'thumbnail_fingerprint'];

    protected $fillable = [
        'tenant_id', 'project_id', 'name', 'method', 'fingerprint', 'confirmed_by', 'confirmed_at',
    ];

    protected $casts = ['confirmed_at' => 'datetime'];

    /** @return HasMany<ExternalCreative, $this> */
    public function creatives(): HasMany
    {
        return $this->hasMany(ExternalCreative::class, 'creative_group_id');
    }

    /** Whether a person has vouched for this grouping, as opposed to a hash having proposed it. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null || in_array($this->method, ['manual', 'confirmed'], true);
    }
}
