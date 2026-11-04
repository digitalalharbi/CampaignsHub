<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Accounts\Services\OwnPendingRegistration;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Services\MembershipSelector;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The portal/workspace switcher's endpoints (ADR 0002).
 *
 * `index` answers "where may I go?" — every portal this user belongs to, which one is active, and the
 * destination to send them to. `switch` changes the active one.
 *
 * The list is always derived from the authenticated user, never from an id in the request, so it
 * cannot be used to enumerate other people's workspaces.
 */
final class MembershipController extends Controller
{
    public function __construct(
        private readonly PortalResolver $resolver,
        private readonly MembershipSelector $selector,
        private readonly MembershipContext $context,
        private readonly OwnPendingRegistration $pendingRegistration,
    ) {}

    /** GET /auth/memberships */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $this->resolver->membershipsFor($user);
        $active = $this->context->membership();

        // SUPERADMIN-SUPPORT-ACCESS-001 — during a support visit the visited workspace is the only one there is.
        if ($active !== null && $active->getAttribute('support_session_id') !== null) {
            return ApiResponse::success([
                'memberships' => [$this->present($active, $active)],
                'current' => $this->present($active, $active),
                'destination' => ($active->portal instanceof Portal ? $active->portal : Portal::from((string) $active->portal))->landingPath(),
                'needs_switcher' => false,
                'requested_portal' => null,
                'requested_portal_held' => null,
                'pending_registration' => null,
            ], 'Memberships.');
        }

        // The portal the visitor was heading for before they signed in. Honoured only if they hold
        // it — `landingPathFor` refuses to invent a membership — so it can carry the journey through
        // authentication without becoming a way to ask for a portal you do not have.
        $requested = Portal::tryFrom((string) $request->query('portal', ''));

        /*
         * WORKSPACE-SWITCH-001 — no workspace yet, and an application of their own still on its way.
         *
         * Holding any membership skips this entirely: whoever has a workspace routes like anybody else, whatever
         * concession or waiver got them there. The id is all that is said, and it opens only the status screen.
         */
        $pending = $memberships->isEmpty() ? $this->pendingRegistration->for($user) : null;

        return ApiResponse::success([
            'memberships' => $memberships->map(fn (Membership $m) => $this->present($m, $active))->all(),
            'current' => $active !== null ? $this->present($active, $active) : null,
            // Where the frontend should land this user right now.
            // LOGIN-SINGLE-DOOR-001 — `intended` is the page the sign-in was interrupted on: a request, never a claim.
            'destination' => $this->resolver->postAuthDestination($user, $requested, is_string($request->query('intended')) ? $request->query('intended') : null),
            'needs_switcher' => $this->resolver->needsSwitcher($user),
            /*
             * What the visitor ASKED for, and whether they have it (LOGIN-001).
             *
             * `destination` alone cannot express "you chose Agency and you are not in one" — it just
             * quietly returns the advertiser portal, and the person who deliberately picked a tab on
             * the sign-in page arrives somewhere else with no explanation. These two fields let the
             * interface say it plainly and offer the portal they DO hold, instead of dropping them
             * into a dashboard that is not theirs and letting them work out why.
             *
             * Null when no portal was requested — a plain `/login` claims nothing.
             */
            'requested_portal' => $requested?->value,
            'requested_portal_held' => $requested === null ? null : $this->resolver->holds($user, $requested),
            'pending_registration' => $pending !== null
                ? ['id' => (string) $pending->getKey(), 'state' => $pending->state->value]
                : null,
        ], 'Memberships.');
    }

    /**
     * POST /auth/memberships/switch — body: { membership_id }.
     *
     * A membership id that is not one of the caller's active memberships is refused with 403 rather
     * than silently ignored, so a probe for another tenant's id gets no useful signal and no access.
     */
    public function switch(Request $request): JsonResponse
    {
        $data = $request->validate(['membership_id' => ['required', 'string']]);
        $user = $request->user();

        $membership = $this->selector->select($request, $user, $data['membership_id']);

        abort_if($membership === null, 403, 'That workspace is not available to you.');

        $this->context->set($membership);

        return ApiResponse::success([
            'current' => $this->present($membership, $membership),
            'destination' => $membership->portal->landingPath(),
        ], 'Workspace switched.');
    }

    /** @return array<string, mixed> */
    private function present(Membership $membership, ?Membership $active): array
    {
        return [
            'id' => (string) $membership->getKey(),
            'portal' => $membership->portal->value,
            'portal_path' => $membership->portal->path(),
            'landing_path' => $membership->portal->landingPath(),
            'role' => $membership->role,
            'is_default' => $membership->is_default,
            'is_active' => $active !== null && $active->is($membership),
            'tenant' => [
                'id' => (string) $membership->tenant_id,
                'name' => $membership->tenant?->name,
                'slug' => $membership->tenant?->slug,
            ],
            // Empty = unrestricted within the tenant; a list is a hard ceiling on what this
            // membership may reach, whatever the role would otherwise allow.
            'client_scope_ids' => $membership->clientScopeIds(),
            'project_scope_ids' => $membership->projectScopeIds(),
        ];
    }
}
