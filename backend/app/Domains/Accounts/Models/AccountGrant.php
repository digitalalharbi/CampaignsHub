<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Models;

use App\Domains\Tenancy\Models\Concerns\HasUuidKey;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One administrative exception for one account (GRANT-001).
 *
 * Deliberately NOT tenant-scoped. A grant is a record the platform owner keeps about a tenant, read
 * from the console (which crosses tenants by design) and from the entitlement engine (which already
 * holds the tenant it is asking about). Attaching `BelongsToTenant` would mean the console could not
 * list them and the engine would have to enter a scope to answer a question about a tenant it was
 * handed — both worse than passing the id explicitly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $value
 * @property string $reason
 * @property int|null $granted_by
 * @property Carbon $granted_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 */
final class AccountGrant extends Model
{
    use HasUuidKey;

    /** A single nav capability, beyond what the portal currently entitles this workspace to. */
    public const SECTION = 'section';

    /** A marketing module the plan did not include. */
    public const MODULE = 'module';

    /** A complimentary subscription — `value` is the plan code. */
    public const PLAN = 'plan';

    /** Everything the portals this workspace holds have to offer. */
    public const FULL_ACCESS = 'full_access';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::SECTION, self::MODULE, self::PLAN, self::FULL_ACCESS];
    }

    protected $fillable = [
        'tenant_id', 'kind', 'value', 'reason',
        'granted_by', 'granted_at', 'expires_at',
        'revoked_by', 'revoked_at', 'revoked_reason',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Grants that are actually in force right now.
     *
     * Both conditions are here rather than at the call sites, because "did anyone remember to check
     * the expiry?" is exactly the question a permission system must not depend on. Everything that
     * reads grants reads them through this scope.
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }

    public function isInForce(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
