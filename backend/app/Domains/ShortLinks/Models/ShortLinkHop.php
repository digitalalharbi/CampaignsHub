<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SHORT-LINK-HOPS-001 — one follow of one link, at one moment.
 *
 * The row is a timestamp and two foreign keys. There is nothing here about the person who followed
 * the link, by design and not by omission — see the migration for what is deliberately absent and
 * why the counter on `short_links` remains the authoritative total rather than being derived from
 * these rows.
 */
final class ShortLinkHop extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** A follow is an event: written once, never corrected, so there is no `updated_at` to keep. */
    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'short_link_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'datetime'];

    /** @return BelongsTo<ShortLink, $this> */
    public function shortLink(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class, 'short_link_id');
    }
}
