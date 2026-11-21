<?php

declare(strict_types=1);

namespace App\Domains\Access\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ACCESS-CENTER-001 — a member's override rows, read once per request (or queued job) per tenant.
 *
 * `HasRoles::permissionKeys()` runs on every `hasPermission` call; without this it added one query to each.
 * Bound `scoped`, so the container forgets it between requests and between queued jobs, and every write
 * (`AccessCenter::writeOverrides`, a member's removal) calls `forget()` — a read after a write is never stale.
 */
final class MemberOverrideMemo
{
    /** @var array<string, Collection<int, object>> */
    private array $rows = [];

    /** @return Collection<int, object> rows with permission_key and effect */
    public function for(int $userId, ?string $tenantId): Collection
    {
        $key = $userId.'|'.($tenantId ?? '*');

        return $this->rows[$key] ??= DB::table('member_access_overrides')
            ->where('user_id', $userId)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->where('effect', 'deny'))
            ->get(['permission_key', 'effect']);
    }

    public function forget(): void
    {
        $this->rows = [];
    }
}
