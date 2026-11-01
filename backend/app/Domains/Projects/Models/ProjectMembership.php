<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\Projects\Concerns\BelongsToProject;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectMembership extends Model
{
    use BelongsToProject;
    use BelongsToTenant;
    use HasUuidKey;

    protected $fillable = [
        'tenant_id', 'project_id', 'user_id', 'role', 'permissions', 'denied', 'status',
        'joined_at', 'expires_at', 'created_by',
    ];

    protected $casts = [
        'permissions' => 'array',
        'denied' => 'array',
        'joined_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * A membership that has not lapsed: active AND not past `expires_at`. The one definition —
     * `ProjectAbilities` applies the same test row by row, and every reach query uses this.
     *
     * @param  Builder<ProjectMembership>  $query
     * @return Builder<ProjectMembership>
     */
    public function scopeCurrent($query)
    {
        return $query->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
