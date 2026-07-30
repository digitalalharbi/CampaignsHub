<?php

declare(strict_types=1);

namespace App\Models;

use App\Domains\Access\Models\Concerns\HasRoles;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\MembershipProvisioner;
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

    protected $fillable = [
        'name', 'email', 'password', 'tenant_id', 'is_platform_admin',
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

    /**
     * Escape hatch for the two situations that legitimately create a user without a membership:
     * registration, which grants an `owner` membership itself a moment later, and the tests that
     * exist to prove a membership-less user is refused everything.
     */
    private static bool $autoMembership = true;

    /** @template T @param  callable():T  $callback @return T */
    public static function withoutAutoMembership(callable $callback): mixed
    {
        self::$autoMembership = false;

        try {
            return $callback();
        } finally {
            self::$autoMembership = true;
        }
    }

    protected static function booted(): void
    {
        /*
         * ADR 0002: a tenant user without a membership has no portal and no scope — they would sit
         * in onboarding forever. Guaranteeing it here rather than at each call site is deliberate:
         * users are created in 47 test files, three seeders and several actions, and an invariant
         * that depends on every one of them remembering is not an invariant.
         *
         * Idempotent, so re-running seeders or granting explicitly afterwards is a no-op.
         */
        static::created(function (User $user): void {
            if (self::$autoMembership && $user->tenant_id !== null) {
                app(MembershipProvisioner::class)->ensureForOwnWorkspace($user);
            }
        });

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
