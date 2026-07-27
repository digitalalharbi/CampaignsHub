<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant is the top-level isolation boundary (an agency/company on the platform).
 */
final class Tenant extends Model
{
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = ['name', 'slug', 'status', 'settings', 'portal_domain', 'is_default_portal', 'portal_enabled',
        'account_type', 'enabled_modules', 'subscription_plan', 'onboarding_step', 'onboarding_completed_at'];

    protected $casts = [
        'settings' => 'array',
        'is_default_portal' => 'bool',
        'portal_enabled' => 'bool',
        'enabled_modules' => 'array',
        'onboarding_completed_at' => 'datetime',
    ];

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }
}
