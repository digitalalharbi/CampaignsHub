<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An isolated, brandable space an agency provisions per client. Tenant-scoped.
 */
final class ClientWorkspace extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'slug', 'mode', 'status', 'branding', 'limits', 'custom_domain',
        'client_status', 'service_level', 'industry', 'client_source', 'owner_id', 'source_request_id',
        'priority', 'default_currency', 'timezone', 'language', 'week_start', 'settings', 'archived_at', 'archived_by',
        'tags', 'enabled_services'];

    protected $casts = [
        'branding' => 'array',
        'limits' => 'array',
        'settings' => 'array',
        'tags' => 'array',
        'enabled_services' => 'array',
        'archived_at' => 'datetime',
    ];

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_workspace_user')
            ->withPivot('client_role', 'access_role', 'project_ids', 'granted_by', 'granted_at')
            ->withTimestamps();
    }
}
