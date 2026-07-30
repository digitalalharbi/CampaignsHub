<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Access\Models\Concerns\HasRoles;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Platform user. The public identifier exposed over the API is `uuid`, never the auto-increment `id`.
 *
 * @deprecated-property `tenant_id` — LEGACY DATA ONLY (ADR 0002).
 *
 * It records which tenant a user was originally created under, and is still what factories, seeders
 * and the migration path use to say so. It must NOT be used for authorisation, query scoping,
 * validation or routing: scope comes from the active {@see Membership}, because a person may belong
 * to several tenants and portals and this column can only ever name one.
 *
 * `TenantIdDeprecationTest` fails if that line is crossed. `docs/TENANT_ID_MIGRATION.md` lists the
 * three consumers still to be moved (account suspension, sign-in suspension, onboarding step) and
 * the order to remove them before the column itself can be dropped.
 */
class User extends Authenticatable
{
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
        'name', 'email', 'password', 'tenant_id',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
}
