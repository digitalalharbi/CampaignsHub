<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which projects may this person be SENT figures about — MAIL-001.
 *
 * ## Why a scheduled job needs its own answer, and why it must not invent one
 *
 * Every scoped read in this product runs inside a request, where `MembershipContext` already knows
 * which membership is acting. A digest has no request: it runs from the scheduler, for a user who is
 * asleep. So the ceiling has to be resolved from the membership row directly.
 *
 * That is the dangerous part, and it is the whole reason this class exists rather than a `->where()`
 * inside the mailer. An email is the one surface a recipient cannot be re-authorised on: once a
 * client's spend is in someone's inbox, no permission change takes it back. So this resolves the
 * SAME ceiling the request path resolves, by the same rules, and every deviation is in the
 * fail-closed direction:
 *
 * - **A membership that names clients or projects is a ceiling**, and it outranks any permission —
 *   the same precedence `ClientScopeResolver` documents (REG-001). The narrower answer wins.
 * - **No membership at all means nothing**, never everything. A user whose membership was revoked
 *   between the schedule and the send gets no email rather than the last one they were entitled to.
 * - **`clients.view_all` is a positive grant**, never inferred from an absence of scope rows.
 * - **A preference can only NARROW.** Someone who picks three projects in their settings gets those
 *   three intersected with the ceiling; picking a project they cannot reach adds nothing.
 *
 * The last rule is the one an attacker would try: `project_ids` in the preferences table is written
 * by the user, so it is an input, not an authorisation.
 *
 * ## Two bounds, on purpose
 *
 * Every `Project` query here also carries an explicit `tenant_id`, even though `BelongsToTenant`
 * already applies a global scope. That is not redundancy. `TenantScope` fails closed when no tenant
 * is resolved — which is exactly the state a scheduler runs in — so a caller that forgot to set the
 * context would get an empty digest rather than a wrong one. The explicit bound makes the class
 * correct in either case, and the global scope stays as the outer guard that catches the reverse
 * mistake: a caller whose context is set to a DIFFERENT tenant than the id it passed.
 */
final class DigestScope
{
    /**
     * The project ids this user may be sent figures about, after their own preference is applied.
     *
     * An empty list means «send nothing» and callers must treat it that way — it is never a stand-in
     * for «everything».
     *
     * @return list<string>
     */
    public function projectIdsFor(User $user, string $tenantId): array
    {
        $membership = Membership::query()
            ->with('scopes')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        // No active membership in this tenant → nothing. Not «all of the tenant's projects».
        if ($membership === null) {
            return [];
        }

        $ceiling = $this->ceilingFor($user, $membership, $tenantId);

        return $this->narrowToPreference($ceiling, $user, $tenantId);
    }

    /**
     * Whether this person's own preference NARROWED the digest, and by how much.
     *
     * PROJECT-DIGEST-SCOPE-001. A digest covering three of somebody's twelve projects reads exactly
     * like one covering all twelve: the same heading, the same «across your projects». So a reader
     * whose client is missing cannot tell whether that project had no activity or whether they
     * narrowed their own preference months ago and forgot.
     *
     * Null when nothing was narrowed, which is the ordinary case and needs no sentence — a caveat on
     * every digest is a caveat nobody reads.
     *
     * Deliberately a separate, read-only question. `projectIdsFor()` decides who is SENT what and is
     * not touched by this: the answer here changes a line of copy and never a recipient.
     *
     * @return array{chosen:int,ceiling:int}|null
     */
    public function narrowing(User $user, string $tenantId): ?array
    {
        $membership = Membership::query()
            ->with('scopes')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        if ($membership === null) {
            return null;
        }

        $ceiling = $this->ceilingFor($user, $membership, $tenantId);
        $chosen = $this->narrowToPreference($ceiling, $user, $tenantId);

        return count($chosen) < count($ceiling)
            ? ['chosen' => count($chosen), 'ceiling' => count($ceiling)]
            : null;
    }

    /**
     * The membership's own ceiling, before the user's preference narrows it.
     *
     * @return list<string>
     */
    private function ceilingFor(User $user, Membership $membership, string $tenantId): array
    {
        $projectScope = $membership->projectScopeIds();

        // A named PROJECT scope is the narrowest statement anyone made — it wins outright.
        if ($projectScope !== []) {
            return $this->existing($projectScope, $tenantId);
        }

        $clientScope = $membership->clientScopeIds();

        // A named CLIENT scope means every project under those clients, and only those.
        if ($clientScope !== []) {
            return Project::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('client_workspace_id', $clientScope)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
        }

        /*
         * No named scope. Unrestricted access is a POSITIVE grant and is checked here, never
         * inferred from the empty scope above — that inversion is what `ClientScopeResolver` was
         * written to prevent, and a digest is the worst place to repeat it.
         */
        if ($user->is_platform_admin || $user->hasPermission('clients.view_all')) {
            return Project::query()
                ->where('tenant_id', $tenantId)
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all();
        }

        return [];
    }

    /**
     * The user's chosen projects, intersected with what they may reach.
     *
     * `project_ids` is written by the user through their own settings, so it is an INPUT. Choosing a
     * project outside the ceiling adds nothing; an empty or absent choice means «everything I may
     * reach», because a preference that has never been set must not silence a person's digest.
     *
     * @param  list<string>  $ceiling
     * @return list<string>
     */
    private function narrowToPreference(array $ceiling, User $user, string $tenantId): array
    {
        if ($ceiling === []) {
            return [];
        }

        $row = DB::table('notification_preferences')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->whereNull('client_workspace_id')
            ->first();

        $chosen = $row?->project_ids === null ? null : json_decode((string) $row->project_ids, true);

        if (! is_array($chosen) || $chosen === []) {
            return $ceiling;
        }

        return array_values(array_intersect($ceiling, array_map('strval', $chosen)));
    }

    /**
     * Ids that still name a real project.
     *
     * A scope row outliving the project it named would otherwise put a dead id into a query that
     * returns nothing — harmless — but also into the «you have no data» sentence, which is a
     * different and wrong claim.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function existing(array $ids, string $tenantId): array
    {
        return Project::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }
}
