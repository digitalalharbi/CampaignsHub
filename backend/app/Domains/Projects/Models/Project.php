<?php

declare(strict_types=1);

namespace App\Domains\Projects\Models;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Models\Concerns\BelongsToTenant;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A project inside a client workspace. Owns its own sources/campaigns/reports (bindings added by
 * their domains). Tenant-scoped and workspace-scoped.
 */
final class Project extends Model
{
    use BelongsToTenant;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id', 'client_workspace_id', 'account_manager_id', 'name', 'status', 'setup_completion', 'meta',
    ];

    protected $casts = ['meta' => 'array', 'setup_completion' => 'integer'];

    /** @return BelongsTo<ClientWorkspace, $this> */
    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }
}
