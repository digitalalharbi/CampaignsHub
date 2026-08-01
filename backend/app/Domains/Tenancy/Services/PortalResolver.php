<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns "who is this user?" into "which portal do they land in?" (ADR 0002).
 *
 * The rule is deliberately membership-driven rather than account-type-driven: an account type is a
 * property of a *workspace*, while a portal is a property of a *membership*, and one user may hold
 * several. One active membership routes straight through; several present a switcher.
 *
 * Nothing here trusts a portal supplied by the client. A requested portal is only honoured when the
 * user actually holds an active membership for it — otherwise the user's default is used.
 */
final class PortalResolver
{
    /**
     * @return Collection<int, Membership>
     *
     * Memberships for a WITHDRAWN portal are left out (INFL-OFF-001).
     *
     * This is the one place every routing decision reads — the destination after sign-in, whether the
     * switcher is needed, and whether a requested portal is held — so filtering here is what stops
     * the product delivering somebody to a portal that will then refuse them. An operator whose only
     * membership is the influencers portal lands on `/onboarding` rather than on a redirect chain,
     * and one who also holds the agency portal is taken straight there instead of being shown a
     * switcher with a dead option in it.
     *
     * The rows are untouched and still active. Nothing was revoked: the day the flag flips back, the
     * same membership resolves again with no migration and no re-grant.
     */
    public function membershipsFor(User $user): Collection
    {
        return Membership::query()
            ->forUser($user->id)
            ->active()
            ->with(['tenant:id,name,slug,account_type', 'scopes'])
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get()
            ->filter(fn (Membership $m) => $m->portal->isEnabled())
            ->values();
    }

    /**
     * The membership the user should land on. `$requested` is the portal the visitor asked for
     * (from the URL they were heading to before signing in) and is honoured ONLY if they hold it.
     */
    public function resolve(User $user, ?Portal $requested = null): ?Membership
    {
        $memberships = $this->membershipsFor($user);

        if ($memberships->isEmpty()) {
            return null;
        }

        if ($requested !== null) {
            $match = $memberships->firstWhere('portal', $requested);
            if ($match !== null) {
                return $match;
            }
        }

        return $memberships->firstWhere('is_default', true) ?? $memberships->first();
    }

    /** True when the user must choose, because they belong to more than one portal or workspace. */
    public function needsSwitcher(User $user): bool
    {
        return $this->membershipsFor($user)->count() > 1;
    }

    /**
     * Does this user actually hold this portal?
     *
     * Asked so the interface can SAY SO when the answer is no (LOGIN-001). `resolve()` quietly falls
     * back to the user's own portal when the requested one is not held, which is the right thing for
     * routing and the wrong thing for explaining: someone who deliberately picked "Agency" on the
     * sign-in page and arrived in the advertiser portal has been answered without being told.
     *
     * The platform owner holds `Admin` through the flag rather than a membership, so that case is
     * answered here too — otherwise the console would report itself as a portal its owner lacks.
     */
    public function holds(User $user, Portal $portal): bool
    {
        if ($portal === Portal::Admin) {
            return (bool) $user->is_platform_admin;
        }

        return $this->membershipsFor($user)->contains(
            fn (Membership $m) => $m->portal === $portal,
        );
    }

    /**
     * Where to send the user after authenticating. Falls back to onboarding rather than guessing a
     * portal: a user with no membership has nothing to be shown yet, and inventing one would put
     * them inside a workspace they do not belong to.
     */
    public function landingPathFor(User $user, ?Portal $requested = null): string
    {
        // The platform owner belongs to no tenant on purpose, so they have no membership to resolve.
        // Before this, they fell through to `/onboarding` — the person who owns the system was asked
        // to set up a workspace like a brand-new customer, and had no console at all.
        if ($user->is_platform_admin) {
            return Portal::Admin->landingPath();
        }

        $membership = $this->resolve($user, $requested);

        if ($membership === null) {
            return '/onboarding';
        }

        if ($requested === null && $this->needsSwitcher($user)) {
            return '/switch';
        }

        return $membership->portal->landingPath();
    }

    /** Records which membership was last used, so the switcher can lead with it next time. */
    public function markUsed(Membership $membership): void
    {
        $membership->forceFill(['last_used_at' => now()])->save();
    }
}
