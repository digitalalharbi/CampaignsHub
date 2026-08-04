<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Models\AccountGrant;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Reading and writing administrative exceptions (GRANT-001).
 *
 * Two responsibilities, kept together because they are the same rule seen from either side: what a
 * grant ADDS to an account, and what it takes to create or withdraw one.
 *
 * The reading half is used by `AccountEntitlements` on every request that resolves a tenant, so it is
 * memoised per instance — the container binds this as a singleton within the request, and a
 * permission check that hits the database once per menu item is a permission check somebody will
 * eventually cache in the wrong place.
 *
 * The writing half insists on a reason and records the actor. Neither is a validation nicety: the
 * brief requires every change to carry «السبب والمنفذ والتاريخ», and a grant nobody can explain is one
 * nobody dares revoke.
 */
final class AccountGrants
{
    /** @var array<string, Collection<int, AccountGrant>> */
    private array $memo = [];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The grants in force for this account, right now.
     *
     * @return Collection<int, AccountGrant>
     */
    public function inForce(Tenant|string $tenant): Collection
    {
        $id = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

        return $this->memo[$id] ??= AccountGrant::query()
            ->where('tenant_id', $id)
            ->inForce()
            ->orderBy('granted_at')
            ->get();
    }

    /** Every grant this account has ever had, live or not — the console's history view. */
    public function history(Tenant|string $tenant): Collection
    {
        $id = $tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant;

        return AccountGrant::query()->where('tenant_id', $id)->orderByDesc('granted_at')->get();
    }

    /** True when this account has been given everything its portals offer. */
    public function hasFullAccess(Tenant|string $tenant): bool
    {
        return $this->inForce($tenant)->contains(fn (AccountGrant $g) => $g->kind === AccountGrant::FULL_ACCESS);
    }

    /**
     * Extra nav capabilities, beyond what the plan and modules already allow.
     *
     * @return list<string>
     */
    public function sections(Tenant|string $tenant): array
    {
        return $this->inForce($tenant)
            ->where('kind', AccountGrant::SECTION)
            ->pluck('value')->unique()->values()->all();
    }

    /**
     * Extra marketing modules.
     *
     * @return list<string>
     */
    public function modules(Tenant|string $tenant): array
    {
        return $this->inForce($tenant)
            ->where('kind', AccountGrant::MODULE)
            ->pluck('value')->unique()->values()->all();
    }

    /** The plan code granted free of charge, if any. */
    public function complimentaryPlan(Tenant|string $tenant): ?string
    {
        $grant = $this->inForce($tenant)->first(fn (AccountGrant $g) => $g->kind === AccountGrant::PLAN);

        return $grant?->value !== '' ? $grant?->value : null;
    }

    /**
     * Give this account something its plan does not include.
     *
     * @param  int|null  $actorId  the platform user doing it — recorded, never inferred
     */
    public function grant(
        Tenant $tenant,
        string $kind,
        string $value,
        string $reason,
        ?int $actorId,
        ?\DateTimeInterface $expiresAt = null,
    ): AccountGrant {
        if (! in_array($kind, AccountGrant::kinds(), true)) {
            throw new InvalidArgumentException("Unknown grant kind [{$kind}].");
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A grant must say why it was made.');
        }

        // `full_access` names nothing in particular; the empty string keeps the one-live-grant index
        // meaningful rather than letting two full-access grants coexist under different values.
        $value = $kind === AccountGrant::FULL_ACCESS ? '' : trim($value);

        $existing = AccountGrant::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('kind', $kind)->where('value', $value)
            ->inForce()->first();

        if ($existing !== null) {
            // Already held. Handing back the existing row rather than creating a second one means
            // revoking it once actually removes it.
            return $existing;
        }

        $grant = AccountGrant::query()->create([
            'tenant_id' => (string) $tenant->getKey(),
            'kind' => $kind,
            'value' => $value,
            'reason' => trim($reason),
            'granted_by' => $actorId,
            'granted_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $this->forget($tenant);

        $this->audit->log(
            action: 'account.grant.created',
            entityType: AccountGrant::class,
            entityId: (string) $grant->getKey(),
            after: ['kind' => $kind, 'value' => $value, 'expires_at' => $expiresAt?->format(DATE_ATOM)],
            reason: trim($reason),
            tenantId: (string) $tenant->getKey(),
        );

        return $grant;
    }

    /**
     * Take it back.
     *
     * The row is kept and stamped rather than deleted, because "who gave this away, and who took it
     * back?" is the question an audit exists to answer, and a deleted row answers neither.
     */
    public function revoke(AccountGrant $grant, string $reason, ?int $actorId): AccountGrant
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A revocation must say why it was made.');
        }

        if ($grant->revoked_at !== null) {
            return $grant;
        }

        $grant->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actorId,
            'revoked_reason' => trim($reason),
        ])->save();

        $this->forget($grant->tenant_id);

        $this->audit->log(
            action: 'account.grant.revoked',
            entityType: AccountGrant::class,
            entityId: (string) $grant->getKey(),
            after: ['kind' => $grant->kind, 'value' => $grant->value],
            reason: trim($reason),
            tenantId: (string) $grant->tenant_id,
        );

        return $grant->refresh();
    }

    /** @return array<string,mixed> the shape the console renders */
    public function toArray(AccountGrant $grant): array
    {
        return [
            'id' => (string) $grant->getKey(),
            'tenant_id' => (string) $grant->tenant_id,
            'kind' => $grant->kind,
            'value' => $grant->value,
            'reason' => $grant->reason,
            'granted_by' => $grant->granted_by,
            'granted_at' => $grant->granted_at?->toIso8601String(),
            'expires_at' => $grant->expires_at?->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
            'revoked_by' => $grant->revoked_by,
            'revoked_reason' => $grant->revoked_reason,
            'in_force' => $grant->isInForce(),
        ];
    }

    private function forget(Tenant|string $tenant): void
    {
        unset($this->memo[$tenant instanceof Tenant ? (string) $tenant->getKey() : $tenant]);
    }
}
