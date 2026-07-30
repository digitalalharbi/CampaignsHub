<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership of one portal, in one tenant (ADR 0002).
 *
 * Deliberately does NOT use {@see \App\Domains\Tenancy\Models\Concerns\BelongsToTenant}. That trait
 * adds a global scope filtering by the *current* tenant, which is exactly wrong here: the whole point
 * of this table is to answer "which tenants and portals does this user belong to?" — a question that
 * necessarily crosses tenants, and is asked before a current tenant has been established at all.
 *
 * Isolation is therefore enforced explicitly at every call site: memberships are only ever read for
 * the authenticated user (`forUser`), never for an arbitrary user id from a request.
 */
final class Membership extends Model
{
    use HasUuidKey;

    protected $table = 'memberships';

    protected $fillable = [
        'user_id', 'tenant_id', 'portal', 'workspace_id', 'client_workspace_id',
        'role', 'status', 'is_default', 'last_used_at', 'invited_by',
    ];

    protected function casts(): array
    {
        return [
            'portal' => Portal::class,
            'is_default' => 'bool',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function clientWorkspace(): BelongsTo
    {
        return $this->belongsTo(ClientWorkspace::class);
    }

    /** Only ever called with the authenticated user's id — never with an id taken from a request. */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** A membership confined to a single client space (the agency's isolated client portal). */
    public function isClientScoped(): bool
    {
        return $this->client_workspace_id !== null;
    }
}
