<?php

declare(strict_types=1);

namespace App\Domains\Influencers\Services;

use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What does a CREATOR reach? (INFL-002, ADR 0002)
 *
 * The influencers portal has two populations inside it and they are not two permission levels of the
 * same thing — they are opposite sides of an agreement:
 *
 *   the AGENCY side  — reached through permissions (`influencers.view`, `.manage`, `.view_costs`),
 *                      narrowed by the client-scope ceiling, and served by CollaborationController.
 *   the CREATOR side — reached through this class, and through nothing else.
 *
 * A creator holds no `influencers.*` permission, so every agency endpoint already refuses them
 * without a line of code being written for the purpose. That is the intended default, and the tests
 * assert it rather than trusting it.
 *
 * Fail-closed, in the same shape as ClientScopeResolver: **no roster link means NO collaborations,
 * not all of them.** A user whose link failed to write, or was removed, sees an empty portal — which
 * is noticed at once and harms nobody. The inverse would hand them the whole agency's roster.
 *
 * The visibility gate is `terms_sent_at`, not status. A collaboration the agency is still drafting
 * has not been offered to anyone, and a creator seeing an internal draft would be reading a fee that
 * is still being argued about.
 */
final class CreatorAccess
{
    /**
     * The roster entries this login IS, within the current tenant.
     *
     * Plural because the tenant scope is applied by the model's global scope, and a person may
     * legitimately appear once per tenant; within one tenant the partial unique index holds it to one.
     *
     * @return list<string>
     */
    public function rosterIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return Influencer::query()
            ->where('user_id', $user->getKey())
            ->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
    }

    /** True when this login has a creator identity here at all. */
    public function isCreator(?User $user): bool
    {
        return $this->rosterIds($user) !== [];
    }

    /** The single roster entry this login is, or null. Used for the creator's own profile screen. */
    public function profile(?User $user): ?Influencer
    {
        if ($user === null) {
            return null;
        }

        return Influencer::query()->where('user_id', $user->getKey())->first();
    }

    /**
     * The creator's collaborations: theirs, and only the ones actually offered to them.
     *
     * Both halves matter. Dropping the first shows one creator another's work; dropping the second
     * shows them a draft as though it were an offer.
     *
     * @return Builder<InfluencerCollaboration>
     */
    public function collaborations(?User $user): Builder
    {
        $ids = $this->rosterIds($user);

        return InfluencerCollaboration::query()
            // whereIn over an empty list yields nothing, which is the fail-closed answer we want.
            ->whereIn('influencer_id', $ids)
            ->whereNotNull('terms_sent_at');
    }

    /** One collaboration the creator may see, or null — identical for "not yours" and "not offered". */
    public function collaboration(?User $user, string $id): ?InfluencerCollaboration
    {
        return $this->collaborations($user)->whereKey($id)->first();
    }

    /**
     * One deliverable the creator may act on, or null.
     *
     * Reached THROUGH the collaboration rather than by its own id, so a deliverable id guessed or
     * copied from elsewhere cannot be submitted against — the parent's ownership is re-checked every
     * time rather than assumed from the child.
     */
    public function deliverable(?User $user, string $collaborationId, string $deliverableId): ?InfluencerDeliverable
    {
        $collaboration = $this->collaboration($user, $collaborationId);

        if ($collaboration === null) {
            return null;
        }

        return $collaboration->deliverables()->whereKey($deliverableId)->first();
    }
}
