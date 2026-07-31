<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The five portals are five products, not one product with five labels (REG-001).
 *
 * Every actor below holds the FULL permission catalogue. That is deliberate and it is what makes
 * this file worth having: if any refusal here could be explained by a missing permission, the test
 * would be proving something weaker than it claims. The only thing separating these actors is which
 * portal their membership names.
 *
 * Written because the separation had already collapsed once. The nav was derived from the
 * workspace's account type through a `personal` / `company` fork whose permissive branch was the
 * agency console and whose FALLBACK was that same branch — so a freelancer, an in-house team, and
 * every account that never answered the question were handed the agency's menu inside the
 * advertiser portal, and the shared engines behind it were gated by authentication alone.
 */
final class PortalDistinctnessTest extends TestCase
{
    use RefreshDatabase;

    /** Endpoints that belong to exactly one portal, named by the portal that owns them. */
    private const OWNED = [
        'agency' => ['/api/v1/app/clients', '/api/v1/app/requests', '/api/v1/client-workspaces'],
        'influencers' => ['/api/v1/influencers/roster', '/api/v1/influencers/collaborations'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenant(string $slug, ?string $accountType = null): Tenant
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug.'-'.uniqid(), 'status' => 'active',
            'account_type' => $accountType, 'enabled_modules' => ['paid_media', 'influencer_marketing'],
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        return $tenant;
    }

    /** @param  list<string>  $clientIds */
    private function member(Tenant $tenant, string $email, Portal $portal, array $clientIds = []): User
    {
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'owner'], ['name' => 'Owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create(['name' => 'U', 'email' => $email, 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant, $portal, 'owner', $clientIds);
        $user->assignRole($role);

        return $user;
    }

    // ── Where each actor lands ────────────────────────────────────────────────────────────────

    public function test_the_platform_owner_lands_in_the_admin_console_and_holds_no_membership(): void
    {
        $admin = User::create(['name' => 'Owner', 'email' => 'owner@platform.test',
            'password' => 'secret1234', 'email_verified_at' => now()]);
        // forceFill, because `is_platform_admin` is not mass-assignable — becoming the platform
        // owner is not something a request payload may do.
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.destination', '/admin')
            ->assertJsonPath('data.memberships', []);

        // The console is theirs…
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/tenants')->assertOk();

        // …and the tenant portals are not: they administer workspaces rather than sitting inside one.
        foreach (self::OWNED['agency'] as $endpoint) {
            $this->actingAs($admin, 'sanctum')->getJson($endpoint)->assertForbidden();
        }
    }

    public function test_the_advertiser_lands_in_app_and_is_refused_every_agency_surface(): void
    {
        $tenant = $this->tenant('adv', 'brand');
        $user = $this->member($tenant, 'adv@a.test', Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');

        foreach (self::OWNED['agency'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)
                ->assertForbidden("an advertiser must not reach {$endpoint}");
        }

        // "Who on the team may reach which client" is the agency portal's own question, and the one
        // section the advertiser portal has no equivalent of. Not open to an advertiser.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/team')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/agency/dashboard')->assertForbidden();
    }

    public function test_the_agency_lands_in_agency_and_is_refused_the_influencer_portal(): void
    {
        $tenant = $this->tenant('agy', 'agency');
        $user = $this->member($tenant, 'agy@a.test', Portal::Agency);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.destination', '/agency');

        foreach (self::OWNED['agency'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)->assertOk();
        }
        foreach (self::OWNED['influencers'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)
                ->assertForbidden("an agency membership alone must not open {$endpoint}");
        }
    }

    public function test_the_influencer_portal_member_is_refused_the_agency_surfaces(): void
    {
        $tenant = $this->tenant('ugc', 'agency');
        $user = $this->member($tenant, 'ugc@a.test', Portal::Influencers);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.destination', '/influencers');

        foreach (self::OWNED['influencers'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)->assertOk();
        }
        foreach (self::OWNED['agency'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)->assertForbidden();
        }
    }

    // ── Tampering ─────────────────────────────────────────────────────────────────────────────

    /**
     * The portal a client ASKS for is a preference, never a claim.
     *
     * `?portal=agency` from someone who holds only an advertiser membership must return the
     * advertiser's destination — not the agency's, and not an error that reveals the agency exists
     * for this tenant.
     */
    public function test_asking_for_a_portal_you_do_not_hold_returns_your_own(): void
    {
        $tenant = $this->tenant('pref', 'brand');
        $user = $this->member($tenant, 'pref@a.test', Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships?portal=agency')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');
    }

    /**
     * …and SAYS SO, rather than only routing around it (LOGIN-001).
     *
     * `destination` alone cannot express "you chose Agency and you are not in one" — it just returns
     * the advertiser portal, so someone who deliberately picked a tab on the sign-in page arrives
     * somewhere else with nothing to explain why. The interface needs both halves of the answer.
     */
    public function test_the_response_reports_whether_the_requested_portal_is_held(): void
    {
        $tenant = $this->tenant('report', 'brand');
        $user = $this->member($tenant, 'report@a.test', Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships?portal=agency')
            ->assertOk()
            ->assertJsonPath('data.requested_portal', 'agency')
            ->assertJsonPath('data.requested_portal_held', false);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships?portal=app')
            ->assertOk()
            ->assertJsonPath('data.requested_portal', 'app')
            ->assertJsonPath('data.requested_portal_held', true);

        // Nothing requested is not a refusal — both fields stay null so the interface says nothing.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()
            ->assertJsonPath('data.requested_portal', null)
            ->assertJsonPath('data.requested_portal_held', null);
    }

    /** The platform owner holds `admin` through the flag, not a membership — and that counts. */
    public function test_the_owner_console_counts_as_held_by_the_platform_owner(): void
    {
        $admin = User::create(['name' => 'Owner', 'email' => 'holds-admin@platform.test',
            'password' => 'secret1234', 'email_verified_at' => now()]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/auth/memberships?portal=admin')
            ->assertOk()->assertJsonPath('data.requested_portal_held', true);

        $tenant = $this->tenant('not-admin', 'brand');
        $user = $this->member($tenant, 'not-admin@a.test', Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/memberships?portal=admin')
            ->assertOk()->assertJsonPath('data.requested_portal_held', false);
    }

    /** Switching to a membership id you do not own is refused, not honoured. */
    public function test_switching_to_someone_elses_membership_is_refused(): void
    {
        $mine = $this->tenant('mine', 'brand');
        $me = $this->member($mine, 'me@a.test', Portal::App);

        $theirs = $this->tenant('theirs', 'agency');
        $them = $this->member($theirs, 'them@a.test', Portal::Agency);
        $theirMembership = $them->memberships()->firstOrFail();

        app(TenantContext::class)->setTenantId($mine->id);

        $this->actingAs($me, 'sanctum')
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $theirMembership->getKey()])
            ->assertForbidden();

        // …and the refusal did not quietly move them anyway.
        $this->actingAs($me, 'sanctum')->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');
    }

    /**
     * A client id typed into the URL reaches nothing outside the membership's scope.
     *
     * The scope is a ceiling the database applies, so this is a 403/404 from the query rather than a
     * page that renders with someone else's rows.
     */
    public function test_a_client_id_outside_the_membership_scope_reaches_nothing(): void
    {
        $tenant = $this->tenant('scoped', 'agency');

        $mine = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'Mine',
            'slug' => 'mine-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $notMine = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'Not mine',
            'slug' => 'not-mine-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        $user = $this->member($tenant, 'scoped@a.test', Portal::Agency, [(string) $mine->id]);

        $this->actingAs($user, 'sanctum')->getJson("/api/v1/client-workspaces/{$mine->id}")->assertOk();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/v1/client-workspaces/{$notMine->id}");
        $this->assertContains($response->getStatusCode(), [403, 404],
            'a client outside the membership scope must not resolve');

        // The list obeys the same ceiling — one client, not two.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/client-workspaces')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
    }

    /** No membership at all reaches nothing — fail-closed, not "everything in some tenant". */
    public function test_a_user_with_no_membership_is_refused_every_portal(): void
    {
        $user = User::create(['name' => 'Nobody', 'email' => 'nobody@a.test',
            'password' => 'secret1234', 'email_verified_at' => now()]);

        foreach ([...self::OWNED['agency'], ...self::OWNED['influencers'], '/api/v1/projects'] as $endpoint) {
            $this->actingAs($user, 'sanctum')->getJson($endpoint)
                ->assertForbidden("a membership-less user must not reach {$endpoint}");
        }
    }

    // ── The declared surfaces themselves ──────────────────────────────────────────────────────

    /**
     * The section catalogues must stay different. If two portals ever declare the same sections,
     * they are the same product and this fails — which is the whole regression, caught at its source
     * rather than at a rendered menu.
     */
    public function test_every_pair_of_portals_declares_a_different_surface(): void
    {
        $portals = Portal::cases();

        foreach ($portals as $a) {
            foreach ($portals as $b) {
                if ($a === $b) {
                    continue;
                }
                $this->assertNotEquals($a->sections(), $b->sections(),
                    "{$a->value} and {$b->value} declare identical sections");
            }
        }
    }

    /** The multi-client tooling belongs to the agency and to nobody else. */
    public function test_only_the_agency_portal_offers_the_multi_client_tooling(): void
    {
        foreach (['clients', 'requests'] as $section) {
            $this->assertTrue(Portal::Agency->offers($section));

            foreach ([Portal::App, Portal::Influencers, Portal::Admin] as $portal) {
                $this->assertFalse($portal->offers($section),
                    "{$portal->value} must not offer `{$section}` — that is the agency's");
            }
        }
    }

    /**
     * Money is where the two portals are most easily confused, so state it precisely.
     *
     * Both are paying tenants, so both have `subscriptions` — that is what they pay CampaignsHub.
     * Only the agency has `billing`, because that is what a client pays THEM. The advertiser has no
     * one to invoice, and an invoicing screen in the advertiser portal was part of what made it read
     * as an agency console.
     */
    public function test_only_the_agency_invoices_clients_though_both_pay_for_a_plan(): void
    {
        $this->assertTrue(Portal::App->offers('subscriptions'));
        $this->assertTrue(Portal::Agency->offers('subscriptions'));

        $this->assertTrue(Portal::Agency->offers('billing'));
        $this->assertFalse(Portal::App->offers('billing'));
    }
}
