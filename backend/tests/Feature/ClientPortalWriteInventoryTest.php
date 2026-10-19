<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CP-WRITE-DENY-001 — every write a client-portal session can reach is named here with what gates it.
 *
 * The portal is read plus asking: requests, replies, conversations, quote decisions and paying an issued
 * invoice. Each of those is gated three ways in ClientPortalController: the section's visibility for the
 * workspace (through the owned-* resolvers and requestScope), the session's role (requireActor refuses a
 * client_viewer), and for a NEW request the create_requests capability. The capabilities that would let a
 * client change a platform — integration_admin, campaign_writes, pause_launch, budget_writes —
 * have NO portal endpoint at all, which is the strongest form of «denied». team_admin is the one capability
 * with an endpoint, and it reaches the client's own people only.
 *
 * So a new write route under /client fails this test until somebody names its gate here — which is the
 * review the requirement asks for.
 */
final class ClientPortalWriteInventoryTest extends TestCase
{
    /** uri => the gate, in words. */
    private const REVIEWED = [
        'POST api/v1/client/login/start' => 'public: starts the one-time-code sign-in',
        'POST api/v1/client/login/verify' => 'public: verifies the one-time code',
        'POST api/v1/client/logout' => 'the session ends itself',
        'POST api/v1/client/requests' => 'requests visibility + create_requests capability + not a viewer',
        'POST api/v1/client/requests/{reference}/reply' => 'requestScope (requests visibility, project reach) + not a viewer',
        'POST api/v1/client/messages' => 'messages visibility (whole space) + not a viewer',
        'POST api/v1/client/messages/{thread}' => 'ownedThread (messages visibility) + not a viewer',
        'POST api/v1/client/quotes/{quote}/approve' => 'ownedQuote (invoices visibility, whole space) + not a viewer',
        'POST api/v1/client/quotes/{quote}/reject' => 'ownedQuote (invoices visibility, whole space) + not a viewer',
        'POST api/v1/client/invoices/{invoice}/pay' => 'ownedInvoice (invoices visibility, issued only) + not a viewer',
        // CP-USERS-001 — the client's OWN people, not a platform: client_admin + whole space + team_admin capability.
        'POST api/v1/client/team' => 'client_admin + whole space + team_admin capability; viewers and approvers only',
        'DELETE api/v1/client/team/{membership}' => 'client_admin + whole space + team_admin capability; never themselves',
    ];

    public function test_every_portal_write_is_reviewed_and_none_changes_a_platform(): void
    {
        $writes = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/client/') && $uri !== 'api/v1/client') {
                continue;
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $writes[] = "{$method} {$uri}";
            }
        }
        sort($writes);
        $reviewed = array_keys(self::REVIEWED);
        sort($reviewed);

        $this->assertSame($reviewed, $writes, 'a client-portal write route changed: name its gate in REVIEWED (or remove the stale entry)');

        foreach ($writes as $w) {
            $this->assertDoesNotMatchRegularExpression('#campaign|budget|pause|integration|connect#i', $w, "the portal must not reach a platform write: {$w}");
        }
    }
}
