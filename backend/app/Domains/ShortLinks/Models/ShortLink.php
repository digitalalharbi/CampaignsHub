<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

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

    public const KIND_WHATSAPP = 'whatsapp';

    public const KIND_LINK = 'link';

    protected $fillable = [
        'tenant_id', 'project_id', 'slug', 'kind', 'destination', 'source_value',
        'clicks', 'last_clicked_at', 'is_active', 'created_by',
    ];

    protected $casts = [
        'clicks' => 'integer',
        'last_clicked_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
