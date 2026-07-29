<?php

declare(strict_types=1);

namespace App\Domains\Settings\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable content of ONE public page for ONE tenant. `draft` is the working copy (previewable);
 * `published` is what public visitors actually see. Never render `draft` on a public surface.
 */
final class PublicPageSetting extends Model
{
    use BelongsToTenant;
    use HasUuidKey;

    /** The public surfaces that can be edited from System Settings. */
    public const PAGES = ['home', 'portal_paid', 'portal_influencer', 'portal_tracking'];

    protected $fillable = [
        'tenant_id', 'page', 'draft', 'published', 'version',
        'updated_by', 'published_by', 'published_at',
    ];

    protected $casts = [
        'draft' => 'array',
        'published' => 'array',
        'version' => 'integer',
        'published_at' => 'datetime',
    ];
}
