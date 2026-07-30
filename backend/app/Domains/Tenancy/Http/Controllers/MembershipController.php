<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

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
    ) {}

    /** GET /auth/memberships */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $this->resolver->membershipsFor($user);
        $active = $this->context->membership();

        // The portal the visitor was heading for before they signed in. Honoured only if they hold
        // it — `landingPathFor` refuses to invent a membership — so it can carry the journey through
        // authentication without becoming a way to ask for a portal you do not have.
        $requested = Portal::tryFrom((string) $request->query('portal', ''));

        return ApiResponse::success([
            'memberships' => $memberships->map(fn (Membership $m) => $this->present($m, $active))->all(),
            'current' => $active !== null ? $this->present($active, $active) : null,
            // Where the frontend should land this user right now.
            'destination' => $this->resolver->landingPathFor($user, $requested),
            'needs_switcher' => $this->resolver->needsSwitcher($user),
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
            // Present only for a membership confined to one client space.
            'client_workspace' => $membership->clientWorkspace === null ? null : [
                'id' => (string) $membership->client_workspace_id,
                'name' => $membership->clientWorkspace->name,
                'slug' => $membership->clientWorkspace->slug,
            ],
        ];
    }
}
