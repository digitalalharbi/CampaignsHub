<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Actions;

use App\Domains\Influencers\Models\Influencer;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Give a creator on the roster their own way in (INFL-002).
 *
 * The creator role is `creator` in `Portal::Influencers`, and it deliberately carries NO
 * `influencers.*` permission. That is not an omission to fill in later: a creator is the other party
 * to an agreement, not a junior operator, and every agency endpoint refuses them for the same reason
 * it refuses a stranger. What they may do comes from CreatorAccess and the agreement's state.
 *
 * Three refusals, each of which was a way to hand one person another's earnings:
 *
 *   1. An account that already holds a NON-creator membership in this tenant is never linked. That
 *      is a staff login; making it a creator would put an account manager's own name on the roster
 *      and let them read their colleagues' costs through a surface with no permission checks.
 *   2. An account already linked to a DIFFERENT roster entry is never stolen. Silently re-pointing
 *      it would move one creator's collaborations, fees and briefs to another person's screen.
 *   3. A roster entry already linked to a different account is not quietly re-linked either — the
 *      caller has to unlink first, which is a decision someone makes rather than a side effect.
 *
 * This creates a login but does NOT send anything. Nothing here claims an invitation email went out,
 * because none does: the caller is told the account exists and how it will be reached.
 */
final class LinkCreatorAccount
{
    public function __construct(private readonly GrantMembership $grants) {}

    /**
     * @return array{user: User, membership: Membership, created_account: bool}
     *
     * @throws RuntimeException when linking would move access between people — never a silent no-op.
     */
    public function execute(Influencer $influencer, string $email, ?User $grantedBy = null): array
    {
        $email = Str::lower(trim($email));
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($influencer->tenant_id);

        return DB::transaction(function () use ($influencer, $email, $tenant, $grantedBy): array {
            $user = User::query()->where('email', $email)->first();
            $created = false;

            if ($user === null) {
                $user = User::create([
                    'name' => $influencer->name,
                    'email' => $email,
                    // A random secret, never shown and never guessable. The creator sets their own
                    // password through the ordinary reset flow; a known placeholder here would be a
                    // working credential for every creator in the system.
                    'password' => Str::random(48),
                ]);
                $created = true;
            }

            $this->refuseIfStaff($user, $tenant);
            $this->refuseIfLinkedElsewhere($user, $influencer);

            if ($influencer->user_id !== null && (int) $influencer->user_id !== (int) $user->getKey()) {
                throw new RuntimeException(
                    'This roster entry is already linked to another account. Unlink it first.',
                );
            }

            $membership = $this->grants->execute(new MembershipGrant(
                user: $user,
                tenant: $tenant,
                portal: Portal::Influencers,
                role: 'creator',
                grantedBy: $grantedBy,
            ));

            // forceFill: `user_id` is kept out of $fillable so a roster PATCH cannot carry it.
            $influencer->forceFill(['user_id' => $user->getKey()])->save();

            return ['user' => $user, 'membership' => $membership, 'created_account' => $created];
        });
    }

    /** Withdraw portal access without touching the roster entry or its history. */
    public function unlink(Influencer $influencer): void
    {
        $userId = $influencer->user_id;

        if ($userId === null) {
            return;
        }

        DB::transaction(function () use ($influencer, $userId): void {
            // The membership is revoked, not deleted: who was given access and when is part of the
            // record. Revoked memberships are already excluded everywhere by `active()`.
            Membership::query()
                ->where('user_id', $userId)
                ->where('tenant_id', $influencer->tenant_id)
                ->where('portal', Portal::Influencers->value)
                ->where('role', 'creator')
                ->update(['status' => 'revoked']);

            $influencer->forceFill(['user_id' => null])->save();
        });
    }

    private function refuseIfStaff(User $user, Tenant $tenant): void
    {
        if ($user->is_platform_admin) {
            throw new RuntimeException('This account administers the platform and cannot be a creator.');
        }

        $staff = Membership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->where(fn ($q) => $q->where('portal', '!=', Portal::Influencers->value)->orWhere('role', '!=', 'creator'))
            ->exists();

        if ($staff) {
            throw new RuntimeException('This account already works for this agency and cannot also be a creator here.');
        }
    }

    private function refuseIfLinkedElsewhere(User $user, Influencer $influencer): void
    {
        $taken = Influencer::query()
            ->where('user_id', $user->getKey())
            ->whereKeyNot($influencer->getKey())
            ->exists();

        if ($taken) {
            throw new RuntimeException('This account is already linked to another creator on the roster.');
        }
    }
}
