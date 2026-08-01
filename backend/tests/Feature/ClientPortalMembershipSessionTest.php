<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\ClientPortalToken;
use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The client portal opens for the account the product sends there (REVIEW-001c).
 *
 * The defect was complete and invisible to every status check. `client@demo-portal.local` signs in,
 * the server answers `portal: "portal"` and routes them to `/portal` — and every endpoint under
 * `/client/*` answered 401, because the portal was gated on the ONE-TIME-CODE cookie alone.
 * `ClientPortalIdentity` was already consulted and already preferred the membership, but only to
 * narrow the reach of a session the cookie had already opened. The engine that "wins" could never be
 * the one that let you in, so the product routed an account to a portal it then locked it out of.
 *
 * What these tests hold in place is the boundary, not the fix: the membership opens the portal, and
 * NOTHING else gained anything. An advertiser, an agency operator and a guest are refused exactly as
 * before, the tenant comes from the membership rather than the request, and the synthesised session
 * never reaches the token table that the cutover decision is still counting.
 */
final class ClientPortalMembershipSessionTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded as a LOCAL install: the demo portal accounts are what is under test, and the demo
        // chain is gated on local|demo.
        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();
    }

    private function customer(): User
    {
        return User::query()->where('email', 'client@demo-portal.local')->firstOrFail();
    }

    public function test_a_client_portal_membership_opens_the_portal_with_no_one_time_code(): void
    {
        $this->actingAs($this->customer());

        foreach ([
            '/api/v1/client/session',
            '/api/v1/client/spaces',
            '/api/v1/client/requests',
            '/api/v1/client/quotes',
            '/api/v1/client/invoices',
            '/api/v1/client/messages',
            '/api/v1/client/files',
            '/api/v1/client/campaigns',
            '/api/v1/client/reports',
        ] as $path) {
            $this->getJson($path, $this->spaHeaders)
                ->assertOk("{$path} refused the account the product routes to this portal");
        }
    }

    /**
     * The spaces come from the MEMBERSHIP, not from request rows carrying this address.
     *
     * Deriving them from `external_requests` showed an invited client-portal user an empty portal
     * until somebody happened to submit a request under their email — the space they had been
     * explicitly granted was simply not in the list.
     */
    public function test_the_space_list_is_the_membership_scope(): void
    {
        $user = $this->customer();
        $membership = $user->memberships()->where('portal', Portal::ClientPortal->value)->firstOrFail();
        $scoped = $membership->clientScopeIds();

        $this->assertNotEmpty($scoped, 'the demo customer has no client scope to prove anything with');

        $ids = collect($this->actingAs($user)->getJson('/api/v1/client/spaces', $this->spaHeaders)
            ->assertOk()->json('data.spaces'))->pluck('id')->sort()->values()->all();

        $this->assertSame(collect($scoped)->sort()->values()->all(), $ids);
    }

    /**
     * Nobody else gained anything.
     *
     * The advertiser and the agency operator hold real sessions and real permissions — in their own
     * portals. A widening here would hand one agency's customer correspondence to the next.
     */
    public function test_a_session_without_a_client_portal_membership_is_still_refused(): void
    {
        foreach (['owner@demo-company.local', 'owner@demo-agency.local', 'admin@demo-campaignshub.local'] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->actingAs($user)
                ->getJson('/api/v1/client/requests', $this->spaHeaders)
                ->assertStatus(401, "{$email} reached the client portal");
        }
    }

    public function test_a_guest_is_still_refused(): void
    {
        $this->getJson('/api/v1/client/requests', $this->spaHeaders)->assertStatus(401);
    }

    /**
     * The synthesised session is never written down.
     *
     * `logout` used to revoke whatever `resolveSession` returned. Saving a membership session would
     * mint a brand-new, already-revoked row on every sign-out — inflating the very table
     * PORTAL-AUTH-001c is waiting to see reach zero before the legacy engine can be retired.
     */
    public function test_signing_out_of_a_membership_session_writes_no_token_row(): void
    {
        $before = ClientPortalToken::query()->count();

        $this->actingAs($this->customer())
            ->postJson('/api/v1/client/logout', [], $this->spaHeaders)
            ->assertOk();

        $this->assertSame($before, ClientPortalToken::query()->count());
    }
}
