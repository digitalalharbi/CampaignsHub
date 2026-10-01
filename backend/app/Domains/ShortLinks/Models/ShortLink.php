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

    /**
     * SHORT-LINKS-LANDING-001 — the one landing page this product serves.
     *
     * A KEY, never a URL. A column holding an address would let a short link point a reader at a
     * page this product does not serve, which is cloaking arriving through the back door; a key can
     * only ever name a page that exists in this repository.
     */
    public const LANDING_VIDEO_REQUEST = 'video_request';

    protected $fillable = [
        'tenant_id', 'project_id', 'slug', 'kind', 'destination', 'source_value',
        'clicks', 'cta_clicks', 'last_clicked_at', 'is_active', 'created_by', 'landing_page',
    ];

    /** Whether following this slug opens a page the reader decides from, rather than forwarding. */
    public function opensLandingPage(): bool
    {
        return $this->landing_page === self::LANDING_VIDEO_REQUEST;
    }

    protected $casts = [
        'clicks' => 'integer',
        'cta_clicks' => 'integer',
        'last_clicked_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
