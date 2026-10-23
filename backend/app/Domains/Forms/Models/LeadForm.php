<?php

declare(strict_types=1);

namespace App\Domains\Forms\Models;

use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * FORMS-HUB-001 — a project's lead form. See the migration for how it, its versions and its submissions divide the work.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $slug
 * @property string $status
 * @property ?string $published_version_id
 */
final class LeadForm extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    protected $fillable = ['tenant_id', 'project_id', 'name', 'status', 'published_version_id', 'default_owner_id', 'assignment_mode', 'assignment_owner_ids', 'follow_up_minutes', 'retention_days', 'created_by'];

    protected $casts = ['assignment_owner_ids' => 'array'];

    protected static function booted(): void
    {
        self::creating(function (self $form): void {
            if (($form->slug ?? '') === '') {
                $form->slug = Str::lower(Str::random(12));
            }
        });
    }

    /** @return HasMany<LeadFormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(LeadFormVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<LeadFormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(LeadFormSubmission::class);
    }
}
