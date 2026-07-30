<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use Illuminate\Support\Facades\DB;

/**
 * The one way access is granted (ADR 0002).
 *
 * Creating a user is NOT granting them access. The two were briefly conflated by a model hook that
 * provisioned a membership whenever a user row appeared — convenient, and wrong: it meant an imported
 * contact, a half-built admin form or a stray factory call silently handed someone a workspace. Access
 * is now only ever granted by calling this, which forces every caller to say what it is granting:
 *
 *   tenant + workspace + portal + role + entity scopes + who granted it.
 *
 * Runs in a transaction, so a failure part-way cannot leave a membership without its scopes — a
 * membership whose scope rows failed to insert would be UNRESTRICTED, which is the opposite of what
 * the caller asked for and the most dangerous way for this to go wrong.
 *
 * Idempotent on the membership itself: re-inviting or retrying returns the existing grant rather than
 * duplicating it or tripping the unique index.
 */
final class GrantMembership
{
    public function __construct(private readonly ManageMembershipScopes $scopes) {}

    public function execute(MembershipGrant $grant): Membership
    {
        return DB::transaction(function () use ($grant): Membership {
            $membership = Membership::query()
                ->forUser($grant->user->id)
                ->where('tenant_id', $grant->tenant->id)
                ->where('portal', $grant->portal->value)
                ->where('workspace_id', $grant->workspace?->id)
                ->first();

            if ($membership === null) {
                $membership = Membership::create([
                    'user_id' => $grant->user->id,
                    'tenant_id' => $grant->tenant->id,
                    'workspace_id' => $grant->workspace?->id,
                    'portal' => $grant->portal->value,
                    'role' => $grant->role,
                    'status' => 'active',
                    // The first grant a person receives is where they land; later ones do not steal it.
                    'is_default' => ! Membership::query()->forUser($grant->user->id)
                        ->where('is_default', true)->exists(),
                    'invited_by' => $grant->grantedBy?->id,
                ]);
            }

            /*
             * ADDITIVE. A grant — an invitation, "give them this client too", a retry — may only
             * widen what the person already has. Replacing here meant adding a second client
             * silently removed the first, and re-sending an invitation narrowed someone to whatever
             * that one invitation happened to mention.
             *
             * Taking access away is a different, explicitly named operation:
             * {@see ManageMembershipScopes::remove()} and ::replace().
             */
            if ($grant->clientScopeIds !== null && $grant->clientScopeIds !== []) {
                $this->scopes->add($membership, MembershipScope::TYPE_CLIENT, $grant->clientScopeIds);
            }

            if ($grant->projectScopeIds !== null && $grant->projectScopeIds !== []) {
                $this->scopes->add($membership, MembershipScope::TYPE_PROJECT, $grant->projectScopeIds);
            }

            return $membership->refresh()->load('scopes');
        });
    }
}
