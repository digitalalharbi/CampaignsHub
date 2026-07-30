<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's membership of one portal, in one tenant (ADR 0002).
 *
 * Deliberately does NOT use the BelongsToTenant trait (referenced in prose, not imported, so no one
 * mistakes this for a model that carries it). That trait adds a global scope filtering by the
 * *current* tenant, which is exactly wrong here: the whole point
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
        'user_id', 'tenant_id', 'portal', 'workspace_id',
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

    /**
     * The entities this membership is confined to. EMPTY means unrestricted within the tenant (an
     * agency owner); one or more rows is a hard ceiling that no role can widen.
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(MembershipScope::class);
    }

    /** @return list<string> client workspace ids this membership may reach; empty = all of them. */
    public function clientScopeIds(): array
    {
        return $this->scopes
            ->where('scope_type', MembershipScope::TYPE_CLIENT)
            ->pluck('scope_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /** @return list<string> project ids this membership may reach; empty = all of them. */
    public function projectScopeIds(): array
    {
        return $this->scopes
            ->where('scope_type', MembershipScope::TYPE_PROJECT)
            ->pluck('scope_id')->map(fn ($id) => (string) $id)->values()->all();
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

    /** True when this membership may only reach specific clients, rather than the whole tenant. */
    public function isClientScoped(): bool
    {
        return $this->clientScopeIds() !== [];
    }
}
