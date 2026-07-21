<?php

declare(strict_types=1);

namespace App\Domains\ClientWorkspaces\Models;

use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = ['tenant_id', 'name', 'slug', 'mode', 'status', 'branding', 'limits', 'custom_domain'];

    protected $casts = ['branding' => 'array', 'limits' => 'array'];

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_workspace_user')
            ->withPivot('client_role')
            ->withTimestamps();
    }
}
