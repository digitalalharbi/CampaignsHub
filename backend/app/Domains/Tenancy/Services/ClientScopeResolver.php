<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;

/**
 * Which clients may this request touch? (ADR 0002)
 *
 * The rule was inverted once and it is worth stating plainly, because the mistake is easy and silent:
 *
 *   **No scope rows means NO clients — not all of them.**
 *
 * Treating an empty set as "unrestricted" makes every failure mode generous: a grant whose scope rows
 * failed to insert, a member whose last client was removed, a bug that deleted the wrong rows — each
 * would have widened access to the entire agency instead of narrowing it to nothing. Fail-closed
 * means the opposite: those all end in seeing nothing, which is noticed immediately and harms no one.
 *
 * Unrestricted access is a POSITIVE grant — the `clients.view_all` permission — held by agency owners
 * and admins. It is never inferred from the absence of data.
 */
final class ClientScopeResolver
{
    public const ALL_CLIENTS = 'clients.view_all';

    public function __construct(private readonly MembershipContext $context) {}

    /** True only when the user explicitly holds the all-clients permission. */
    public function hasUnrestrictedAccess(?User $user): bool
    {
        return $user !== null && ($user->is_platform_admin || $user->hasPermission(self::ALL_CLIENTS));
    }

    /**
     * The client ids this request may reach.
     *
     * `null` means "no restriction" and is returned ONLY for a holder of `clients.view_all`. Any
     * other caller gets an explicit list, which may be empty — and an empty list must be treated as
     * "nothing", never as "everything".
     *
     * @return list<string>|null
     */
    public function reachableClientIds(?User $user, ?Membership $membership = null): ?array
    {
        $membership ??= $this->context->membership();
        $named = $membership?->clientScopeIds() ?? [];

        /*
         * A membership that NAMES clients is a ceiling, and it outranks the permission (REG-001).
         *
         * The permission check used to come first, so `clients.view_all` erased an explicit scope
         * entirely — an account manager confined to three clients saw all of them the moment their
         * role happened to include the permission, and the confinement did nothing. Putting the
         * named list first makes the more specific, more deliberate statement win, which is also the
         * fail-closed direction: the narrower of the two answers.
         *
         * Platform staff are the exception, above: they hold no membership at all, so there is no
         * named list to be narrower, and they operate across tenants by design.
         */
        if ($named !== []) {
            return $named;
        }

        if ($this->hasUnrestrictedAccess($user)) {
            return null;
        }

        return [];
    }

    /**
     * Apply the ceiling to a query. Callers do not decide what "unrestricted" means — a holder of
     * the permission gets an untouched query, everyone else gets `whereIn`, and an empty list
     * therefore yields no rows rather than every row.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function constrain($query, ?User $user, string $column = 'client_workspace_id')
    {
        $ids = $this->reachableClientIds($user);

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids);
    }

    /** Whether one specific client is reachable. Used by policies and by route-model binding. */
    public function canReach(?User $user, string $clientId): bool
    {
        $ids = $this->reachableClientIds($user);

        return $ids === null || in_array($clientId, $ids, true);
    }
}
