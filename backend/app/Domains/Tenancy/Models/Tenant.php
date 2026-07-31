<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Accounts\Enums\AccountState;
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

    /*
     * `account_state`, `activated_at`, `state_reason` and `state_changed_at` are absent from
     * $fillable ON PURPOSE (SIGNUP-001).
     *
     * They decide whether an account exists as far as the product is concerned. Listing them here
     * would make them form fields — a settings PATCH carrying `account_state=active` would walk an
     * unpaid, unapproved account straight past verification, approval and payment. They are written
     * only by TransitionAccountState, which refuses illegal moves and audits every legal one.
     */
    protected $fillable = ['name', 'slug', 'status', 'settings', 'portal_domain', 'is_default_portal', 'portal_enabled',
        'account_type', 'enabled_modules', 'subscription_plan', 'onboarding_step', 'onboarding_completed_at'];

    protected $casts = [
        'settings' => 'array',
        'is_default_portal' => 'bool',
        'portal_enabled' => 'bool',
        'enabled_modules' => 'array',
        'onboarding_completed_at' => 'datetime',
        // SIGNUP-001 — the lifecycle position, and when it last moved.
        'account_state' => AccountState::class,
        'activated_at' => 'datetime',
        'state_changed_at' => 'datetime',
    ];

    /** True when this account may actually be used — Active, or Past Due inside its grace period. */
    public function isOperational(): bool
    {
        return ($this->account_state ?? AccountState::Draft)->isOperational();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }
}
