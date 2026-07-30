<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use Illuminate\Support\Facades\DB;

/**
 * Changing what a membership can reach (ADR 0002).
 *
 * Three separate operations, deliberately not one. A single "set scopes" call reads as harmless and
 * silently destroys: adding a second client to an account manager would have removed the first, and
 * re-sending an invitation would have narrowed someone's access to whatever that one invitation
 * happened to mention. Each operation is now named for what it does, so the destructive one has to be
 * chosen on purpose.
 *
 *   add()     — grants more. Idempotent; re-inviting with the same client changes nothing.
 *   remove()  — takes one away, and only that one.
 *   replace() — the ONLY destructive operation, named so an administrator has to mean it.
 */
final class ManageMembershipScopes
{
    /**
     * Grant additional entities. Existing scopes are KEPT — this is what an invitation and a
     * "give them this client too" both do, and neither should cost the member their other clients.
     *
     * @param  list<string>  $ids
     */
    public function add(Membership $membership, string $scopeType, array $ids): Membership
    {
        return DB::transaction(function () use ($membership, $scopeType, $ids): Membership {
            $existing = $membership->scopes()->where('scope_type', $scopeType)
                ->pluck('scope_id')->map(fn ($id) => (string) $id)->all();

            foreach (array_unique($ids) as $id) {
                // Idempotent: re-inviting with a client they already have is a no-op, not a duplicate.
                if (in_array((string) $id, $existing, true)) {
                    continue;
                }

                MembershipScope::create([
                    'membership_id' => $membership->getKey(),
                    'scope_type' => $scopeType,
                    'scope_id' => $id,
                ]);
            }

            return $membership->refresh()->load('scopes');
        });
    }

    /** Withdraw ONE entity. Everything else the membership reaches is untouched. */
    public function remove(Membership $membership, string $scopeType, string $id): Membership
    {
        $membership->scopes()
            ->where('scope_type', $scopeType)
            ->where('scope_id', $id)
            ->delete();

        return $membership->refresh()->load('scopes');
    }

    /**
     * Replace the whole set for one type. THE DESTRUCTIVE ONE — named `replace` rather than `set`
     * so that choosing it is a decision, and so an audit trail can distinguish "we added a client"
     * from "we redefined what this person can see".
     *
     * An empty list leaves the membership reaching NOTHING of that type. It does not mean
     * unrestricted: unrestricted is the `clients.view_all` permission, never an absence of rows.
     *
     * @param  list<string>  $ids
     */
    public function replace(Membership $membership, string $scopeType, array $ids): Membership
    {
        return DB::transaction(function () use ($membership, $scopeType, $ids): Membership {
            $membership->scopes()->where('scope_type', $scopeType)->delete();

            foreach (array_unique($ids) as $id) {
                MembershipScope::create([
                    'membership_id' => $membership->getKey(),
                    'scope_type' => $scopeType,
                    'scope_id' => $id,
                ]);
            }

            return $membership->refresh()->load('scopes');
        });
    }
}
