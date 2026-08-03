<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Access\Models\Concerns\HasRoles;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Support\Concerns\NormalisesPhoneNumbers;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user. The public identifier exposed over the API is `uuid`, never the auto-increment `id`.
 *
 * Belongs to no tenant by itself (ADR 0002). `users.tenant_id` was removed in
 * `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`: it could only ever name ONE
 * workspace, while a person may hold memberships in several — an agency owner who is also another
 * agency's client, a freelancer on two rosters. For those, it answered with whichever tenant was
 * stamped at registration.
 *
 * Which tenant a request is for now comes from the active {@see Membership}, and from nowhere else.
 */
class User extends Authenticatable
{
    use NormalisesPhoneNumbers;

    /** PHONE-001 — normalised to E.164 on save, from every caller. See the trait. */
    protected array $phoneColumns = ['phone'];

    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    /**
     * NOTE the absence of `is_platform_admin`. It is deliberately NOT mass-assignable: it is the only
     * key to the platform console, and a single `update($request->validated())` that happened to
     * carry the field would hand a customer the ability to suspend every tenant. Setting it requires
     * `forceFill`, which cannot happen by accident from request data.
     *
     * `PlatformAdminFlagTest` holds this line.
     */
    protected $fillable = [
        'name', 'email', 'password',
        'first_name', 'last_name', 'job_title', 'phone', 'avatar_path', 'bio',
        'locale', 'timezone', 'date_format', 'number_format', 'theme',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'bool',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'bool',
        ];
    }

    protected static function booted(): void
    {

        static::creating(function (User $user): void {
            if (empty($user->uuid)) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The workspace this user is currently IN, or null (ADR 0002).
     *
     * Was a `belongsTo` on `users.tenant_id`. That column named one workspace forever; this asks the
     * membership layer, so a user who holds two gets the one this request is actually for.
     *
     * Falls back to their default membership when no tenant is bound — a boot payload rendered
     * outside a tenant-scoped request (registration, /me straight after sign-in) still needs to say
     * which workspace the person landed in, and their default is the honest answer to that.
     */
    public function currentTenant(): ?Tenant
    {
        $bound = app(TenantContext::class)->tenantId();

        $membership = $this->memberships()
            ->where('status', 'active')
            ->when($bound !== null, fn ($q) => $q->where('tenant_id', $bound))
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->first();

        return $membership?->tenant;
    }

    /** So `$user->tenant` keeps reading as it always did, now answered by the membership layer. */
    public function getTenantAttribute(): ?Tenant
    {
        return $this->currentTenant();
    }

    /**
     * Every portal this user belongs to, across tenants (ADR 0002). `tenant_id` above is the primary
     * membership kept for the existing tenant scope; this is the full picture the portal switcher and
     * the post-authentication routing are built on.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * The users who belong to a tenant (ADR 0002).
     *
     * The replacement for `where('tenant_id', $id)`, which read a column that named at most one
     * workspace per person. ONE scope rather than the same `whereHas` repeated at each call site,
     * because "who is in this tenant?" was being answered eight different ways and they only have
     * to disagree once for a stranger to pass an assignee check.
     *
     * Revoked memberships do not count: access that outlives its grant is the failure this layer
     * exists to prevent.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeInTenant(Builder $query, ?string $tenantId): Builder
    {
        // Fail-closed. A null tenant is "no tenant established", which reaches nobody — reading it
        // as "any tenant" would make every unbound request a cross-tenant one.
        if ($tenantId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas(
            'memberships',
            fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'active'),
        );
    }
}
