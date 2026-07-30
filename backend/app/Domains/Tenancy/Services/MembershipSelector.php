<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Reads and writes the membership the user has switched into, in the server-side session (ADR 0002).
 *
 * The selection is stored as an id in the session and **re-verified against the database on every
 * request**. Storing the resolved scope itself (tenant, client) would mean a revoked membership kept
 * working until the session expired, and a tampered cookie would be trusted. Here the session can only
 * ever *narrow* the user to something they already hold: an id that is not theirs, or no longer
 * active, resolves to nothing and they fall back to their default.
 */
final class MembershipSelector
{
    private const SESSION_KEY = 'membership_id';

    public function __construct(private readonly PortalResolver $resolver) {}

    /** The membership the user switched into, if it is still genuinely theirs and active. */
    public function selected(Request $request, User $user): ?Membership
    {
        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);
        if (! is_string($id) || $id === '') {
            return null;
        }

        // forUser() + active() are the whole guarantee: ownership and status are re-checked here,
        // every request, rather than trusted from whatever the session happens to hold.
        return Membership::query()->forUser($user->id)->active()->whereKey($id)->first();
    }

    /**
     * Switch into a membership. Returns null — and stores nothing — when the id is not one of the
     * user's active memberships, so an attacker supplying another tenant's id gains nothing.
     */
    public function select(Request $request, User $user, string $membershipId): ?Membership
    {
        $membership = Membership::query()->forUser($user->id)->active()->whereKey($membershipId)->first();

        if ($membership === null) {
            return null;
        }

        // Token clients (and any stateless request) have no session to remember the choice in. The
        // switch is still valid for this request; it simply does not persist beyond it.
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, (string) $membership->getKey());
        }

        $this->resolver->markUsed($membership);

        return $membership;
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }
}
