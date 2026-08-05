<?php

declare(strict_types=1);

namespace App\Domains\Settings\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable content of ONE public page. `draft` is the working copy (previewable); `published` is what
 * public visitors actually see. Never render `draft` on a public surface.
 *
 * PAGES-001 — NOT tenant-scoped, deliberately.
 *
 * There is one marketing homepage and there are three public portals, and they belong to whoever owns
 * the platform. When these rows were per-tenant, the public endpoint served whichever tenant had
 * published most recently — so a customer could rewrite the platform's own front page, and the next
 * customer to publish would take it from them. The platform's row is the one with no `tenant_id`, and
 * `platform()` is the only scope any surface should read.
 */
final class PublicPageSetting extends Model
{
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

    /**
     * The platform's own documents — the only ones anything reads.
     *
     * Legacy per-tenant rows predate PAGES-001 and are left in the table rather than deleted; this is
     * what keeps them out of every query without a migration having to destroy somebody's writing.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePlatform(Builder $query): Builder
    {
        return $query->whereNull('tenant_id');
    }
}
