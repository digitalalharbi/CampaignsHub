<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Accounts\Services\OwnPendingRegistration;
use App\Domains\Identity\Support\AccountSuspension;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\ReviewAccess;
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
    public function __construct(private readonly OwnPendingRegistration $pendingRegistration) {}

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
     *
     * So are memberships of a workspace the person has LOST (WORKSPACE-SWITCH-001) — see {@see isUsable()}.
     */
    public function membershipsFor(User $user): Collection
    {
        return Membership::query()
            ->forUser($user->id)
            ->active()
            ->with(['tenant:id,name,slug,account_type,status,settings', 'scopes'])
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get()
            ->filter(fn (Membership $m) => $this->isUsable($m))
            ->values();
    }

    /**
     * May this membership be worked in right now? (WORKSPACE-SWITCH-001)
     *
     * The membership row says the person belongs; this says the workspace is still there to belong to. A portal that
     * is withdrawn, a workspace that was deleted, one that is suspended or not operating, and a review workspace whose
     * access has ended all answer no.
     *
     * `AccountSuspension` has always said «the person loses THAT workspace», and enforced it only when it was the LAST
     * one: holding a second workspace kept a suspended one fully reachable, as the default or by a switch. And the
     * reverse hole: an ended review workspace, as the default, refused every request — the chooser included — however
     * many live workspaces the person also held. One rule, asked at every door, closes both.
     */
    public function isUsable(Membership $membership): bool
    {
        if (! $membership->portal->isEnabled()) {
            return false;
        }

        // Read without setting the relation: a resolved membership's `tenant` may otherwise carry only a few columns.
        $tenant = $membership->relationLoaded('tenant')
            ? $membership->getRelation('tenant')
            : Tenant::query()->select(['id', 'status', 'settings'])->find($membership->tenant_id);

        return $tenant instanceof Tenant
            && ! AccountSuspension::isBlockedStatus($tenant->status)
            && ! ReviewAccess::hasEnded($tenant);
    }

    /**
     * The review workspace whose end is the reason this person has nowhere left to go.
     *
     * Only asked once nothing usable remains. Without it they would fall through with no scope at all, which is safe
     * but says nothing; with it, `ResolveMembership` answers `review_ended` and the person is told why.
     */
    public function endedReviewMembership(User $user): ?Membership
    {
        return Membership::query()->forUser($user->id)->active()->get()
            ->first(fn (Membership $m) => $m->tenant instanceof Tenant && ReviewAccess::hasEnded($m->tenant));
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

        /*
         * AUTH-SESSION-RACE-OBS — a workspace nobody has configured is not a dashboard yet.
         *
         * The rule above sends somebody to the wizard only when they hold NO MEMBERSHIP, which was
         * written when signing up and being granted a workspace were the same event. Paid
         * registration creates the membership BEFORE onboarding is finished, so that condition
         * stopped covering the case it exists for: a brand-new paid account was sent to
         * `/app/dashboard` for a workspace with no client, no project and no connection.
         *
         * `OnboardingGate` refuses that route on the client and would correct it, which is exactly
         * why this read as intermittent rather than broken — the correction lands only if the gate
         * re-renders before anything else settles, and its own docblock records the case where it
         * does not. Two deciders, one of them missing a condition, is what produced a failure that
         * moved between browsers and looked like a session race.
         *
         * Asked AFTER the switcher on purpose: «which workspace?» has to be answered before «is that
         * one ready?» means anything, and picking one on somebody's behalf is the decision the
         * switcher exists to leave with them.
         */
        // LOGIN-SINGLE-DOOR-001 — a client of an agency has no wizard to finish: the agency's own setup is not their step.
        if ($membership->portal === Portal::ClientPortal) {
            return $membership->portal->landingPath();
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->find($membership->tenant_id);

        /*
         * Only a POSITIVE «not finished» sends them to the wizard.
         *
         * The first spelling of this asked `$membership->tenant?->onboarding_completed_at === null`,
         * which reads the same for «onboarding is unfinished» and «the tenant could not be read at
         * all» — and the second answer sent EVERY user to the wizard, including finished ones. A
         * missing fact must not lock somebody out of the product they have already set up; where the
         * tenant cannot be read, the portal it resolved to is still the better answer, and
         * `OnboardingGate` remains the second check.
         */
        if ($tenant !== null && $tenant->onboarding_completed_at === null) {
            return '/onboarding';
        }

        return $membership->portal->landingPath();
    }

    /**
     * LOGIN-SINGLE-DOOR-001 — the one answer to «where now?», for every way of signing in.
     *
     * In order: their own application still on its way (no workspace yet); the page they were heading for, only if the
     * server agrees they may enter it; otherwise the landing the memberships decide. A page in a portal they hold but are
     * not working in is honoured, and the portal's guard asks before moving the session (#945): nothing here moves it.
     */
    public function postAuthDestination(User $user, ?Portal $requested = null, ?string $intended = null): string
    {
        $pending = $this->membershipsFor($user)->isEmpty() ? $this->pendingRegistration->for($user) : null;
        if ($pending !== null) {
            return '/signup/status?request='.$pending->getKey();
        }

        return $intended !== null && $this->mayEnter($user, $intended)
            ? $intended
            : $this->landingPathFor($user, $requested);
    }

    /** Is this an in-app address, and if it lies inside a portal, one this person holds? */
    public function mayEnter(User $user, string $intended): bool
    {
        if (self::safeInAppPath($intended) === null) {
            return false;
        }

        $portal = self::portalOfPath($intended);

        return $portal === null || $this->holds($user, $portal);
    }

    /**
     * Where a code sign-in lands: inside the client portal or at its front door. It opens nothing else, so it may name nothing else.
     */
    public static function contactDestination(?string $intended): string
    {
        $safe = $intended === null ? null : self::safeInAppPath($intended);

        return $safe !== null && self::portalOfPath($safe) === Portal::ClientPortal ? $safe : Portal::ClientPortal->path();
    }

    /**
     * The value itself when it can only ever stay inside this application and is not a sign-in page; null otherwise.
     *
     * Checked as sent and once decoded, because the browser decodes it again: `%2F%2Fevil.example` must fail here too. A sign-in
     * page is refused so a chain of redirects cannot end where it began.
     */
    public static function safeInAppPath(string $raw): ?string
    {
        if ($raw === '' || strlen($raw) > 2048) {
            return null;
        }

        foreach ([$raw, rawurldecode($raw)] as $value) {
            if (! str_starts_with($value, '/') || str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                return null;
            }

            $path = strtolower((string) preg_replace('/[?#].*$/s', '', $value));
            if (preg_match('#^/(register|forgot-password|reset-password)(/|$)#', $path) === 1 || preg_match('#(^|/)login/?$#', $path) === 1) {
                return null;
            }
        }

        return $raw;
    }

    /** The portal whose route tree this address lies in, or null when it lies in none. `/client` is the portal's older address. */
    private static function portalOfPath(string $path): ?Portal
    {
        $first = explode('/', ltrim((string) preg_replace('/[?#].*$/s', '', rawurldecode($path)), '/'))[0];

        return $first === 'client' ? Portal::ClientPortal : Portal::tryFrom(strtolower($first));
    }

    /** Records which membership was last used, so the switcher can lead with it next time. */
    public function markUsed(Membership $membership): void
    {
        $membership->forceFill(['last_used_at' => now()])->save();
    }
}
