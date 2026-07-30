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
    /** @return Collection<int, Membership> */
    public function membershipsFor(User $user): Collection
    {
        return Membership::query()
            ->forUser($user->id)
            ->active()
            ->with(['tenant:id,name,slug,account_type', 'scopes'])
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get();
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
