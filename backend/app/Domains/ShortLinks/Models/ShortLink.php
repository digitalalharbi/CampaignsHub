<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SHORT-LINKS-001 — one short link: what was chosen, what it resolves to, what it measured.
 *
 * Tenant-scoped for every surface a signed-in person reads. The public hop resolves WITHOUT that
 * scope on purpose — a stranger following a link carries no tenant — which is why the slug is
 * globally unique and why the hop checks `is_active` itself.
 */
final class ShortLink extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /*
     * SHORT-LINKS-001 — delete removes it from the library and stops it resolving.
     *
     * Soft, so the clicks it counted survive as audit. The scope this adds is what makes the public
     * hop stop serving a deleted slug: `resolveAndCount()` removes the TENANT scope for a stranger
     * with no session and nothing else, so the soft-delete scope still applies there.
     */
    use SoftDeletes;

    public const KIND_WHATSAPP = 'whatsapp';

    public const KIND_LINK = 'link';

    /** BIO-PAGE-001 — a page of buttons, each another short link; served on the same hop. */
    public const KIND_BIO = 'bio';

    public const MODE_REDIRECT = 'redirect';

    public const MODE_LANDING = 'landing';

    protected $fillable = [
        'tenant_id', 'project_id', 'slug', 'kind', 'destination', 'source_value', 'message', 'mode',
        'clicks', 'last_clicked_at', 'is_active', 'created_by',
        // SMART-LINK-001 — each optional, each off by default.
        'expires_at', 'utm', 'custom_slug', 'bio',
    ];

    protected $casts = [
        'clicks' => 'integer',
        'last_clicked_at' => 'datetime',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
        'utm' => 'array',
        'custom_slug' => 'boolean',
        'bio' => 'array',
    ];

    /** SMART-LINK-001 — past its expiry, if it has one. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** What the row says about itself: active, disabled by the Owner, or expired by the clock. */
    public function state(): string
    {
        if (! $this->is_active) {
            return 'disabled';
        }

        return $this->isExpired() ? 'expired' : 'active';
    }
}
