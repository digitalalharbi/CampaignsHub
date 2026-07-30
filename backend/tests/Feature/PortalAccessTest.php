<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ADR 0002 — fail-closed portal access.
 *
 * These test the guarantee that matters: a portal is an authorisation boundary enforced in the
 * backend, so calling an endpoint directly, tampering with a membership id, or holding a revoked
 * membership gains nothing that the interface would merely have hidden.
 */
final class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();

        // Probe routes, one per portal, carrying only the real middleware under test. They exist so
        // the gate itself is tested rather than whatever a feature endpoint happens to also check.
        foreach (Portal::cases() as $portal) {
            Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:'.$portal->value])
                ->get('/__probe/'.$portal->value, fn () => response()->json([
                    'portal' => app(MembershipContext::class)->portal()?->value,
                    'tenant' => app(TenantContext::class)->tenantId(),
                ]));
        }
    }

    private function tenant(string $name, ?string $accountType = null): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => str($name)->slug()->value(),
            'status' => 'active', 'account_type' => $accountType,
        ]);
    }

    /** Memberships are granted explicitly here, so the automatic one is opted out of. */
    private function user(Tenant $tenant, string $email): User
    {
        return User::withoutAutoMembership(fn () => User::create([
            'tenant_id' => $tenant->id, 'name' => 'U', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]));
    }

    private function grant(User $user, Tenant $tenant, Portal $portal): Membership
    {
        return app(MembershipProvisioner::class)->ensure($user, $tenant, $portal);
    }

    public function test_a_membership_grants_only_its_own_portal(): void
    {
        $tenant = $this->tenant('Agency Only', 'agency');
        $user = $this->user($tenant, 'agencyonly@test.dev');
        $this->grant($user, $tenant, Portal::Agency);

        $this->actingAs($user, 'sanctum')->getJson('/__probe/agency')->assertOk();

        // Same tenant, same user, different portal — still refused. A portal is not a menu.
        $this->actingAs($user, 'sanctum')->getJson('/__probe/app')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/__probe/influencers')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/__probe/portal')->assertForbidden();
    }

    /** All four portals are supported, not just app and agency. */
    public function test_every_portal_is_reachable_with_its_own_membership(): void
    {
        foreach (Portal::cases() as $portal) {
            $tenant = $this->tenant('T '.$portal->value);
            $user = $this->user($tenant, $portal->value.'@test.dev');
            $this->grant($user, $tenant, $portal);

            $this->actingAs($user, 'sanctum')->getJson('/__probe/'.$portal->value)
                ->assertOk()
                ->assertJsonPath('portal', $portal->value);
        }
    }

    /** Following a link into a portal you genuinely hold switches to it rather than refusing. */
    public function test_a_user_holding_several_portals_may_enter_any_of_them(): void
    {
        $agency = $this->tenant('Multi Agency', 'agency');
        $brand = $this->tenant('Multi Brand', 'brand');
        $user = $this->user($agency, 'multi@test.dev');
        $this->grant($user, $agency, Portal::Agency);
        $this->grant($user, $brand, Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/__probe/agency')
            ->assertOk()->assertJsonPath('tenant', (string) $agency->id);

        // And the tenant scope moves with the membership — not left on the previous tenant.
        $this->actingAs($user, 'sanctum')->getJson('/__probe/app')
            ->assertOk()->assertJsonPath('tenant', (string) $brand->id);
    }

    public function test_a_revoked_membership_grants_nothing(): void
    {
        $tenant = $this->tenant('Revoked Co', 'agency');
        $user = $this->user($tenant, 'revoked@test.dev');
        $membership = $this->grant($user, $tenant, Portal::Agency);

        $this->actingAs($user, 'sanctum')->getJson('/__probe/agency')->assertOk();

        $membership->forceFill(['status' => 'revoked'])->save();

        $this->actingAs($user, 'sanctum')->getJson('/__probe/agency')->assertForbidden();
    }

    /** No membership means no tenant scope at all — never a fallback into someone else's data. */
    public function test_a_user_without_a_membership_is_refused_every_portal(): void
    {
        $tenant = $this->tenant('Stranded Co');
        $user = $this->user($tenant, 'stranded@test.dev');

        foreach (Portal::cases() as $portal) {
            $this->actingAs($user, 'sanctum')->getJson('/__probe/'.$portal->value)->assertForbidden();
        }
    }

    // ---- the switcher ----

    public function test_memberships_endpoint_lists_only_the_callers_own(): void
    {
        $agency = $this->tenant('Mine Agency', 'agency');
        $brand = $this->tenant('Mine Brand', 'brand');
        $me = $this->user($agency, 'me@test.dev');
        $someoneElse = $this->user($brand, 'other@test.dev');
        $this->grant($me, $agency, Portal::Agency);
        $this->grant($me, $brand, Portal::App);
        $this->grant($someoneElse, $brand, Portal::App);

        $res = $this->actingAs($me, 'sanctum')->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/auth/memberships')->assertOk();

        $this->assertCount(2, $res->json('data.memberships'));
        $res->assertJsonPath('data.needs_switcher', true)
            ->assertJsonPath('data.destination', '/switch');
    }

    public function test_switching_moves_the_active_membership_and_its_scope(): void
    {
        $agency = $this->tenant('Switch Agency', 'agency');
        $brand = $this->tenant('Switch Brand', 'brand');
        $user = $this->user($agency, 'switch@test.dev');
        $this->grant($user, $agency, Portal::Agency);
        $appMembership = $this->grant($user, $brand, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $appMembership->getKey()])
            ->assertOk()
            ->assertJsonPath('data.destination', '/app/dashboard')
            ->assertJsonPath('data.current.portal', 'app');
    }

    /** Handing over another user's membership id must not switch into their workspace. */
    public function test_switching_to_a_membership_that_is_not_yours_is_refused(): void
    {
        $mine = $this->tenant('Attacker Co', 'brand');
        $theirs = $this->tenant('Victim Co', 'agency');
        $attacker = $this->user($mine, 'attacker@test.dev');
        $victim = $this->user($theirs, 'victim@test.dev');
        $this->grant($attacker, $mine, Portal::App);
        $victimMembership = $this->grant($victim, $theirs, Portal::Agency);

        $this->actingAs($attacker, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $victimMembership->getKey()])
            ->assertForbidden();

        // And the attacker is still confined to their own tenant afterwards.
        $this->actingAs($attacker, 'sanctum')->getJson('/__probe/app')
            ->assertOk()->assertJsonPath('tenant', (string) $mine->id);
    }

    public function test_switching_to_a_made_up_membership_id_is_refused(): void
    {
        $tenant = $this->tenant('Bogus Co', 'brand');
        $user = $this->user($tenant, 'bogus@test.dev');
        $this->grant($user, $tenant, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => '00000000-0000-0000-0000-000000000000'])
            ->assertForbidden();
    }

    /**
     * A membership revoked after the user switched into it must stop working on the next request,
     * not when the session happens to expire — which is why the selection is re-verified every time.
     */
    public function test_a_membership_revoked_after_switching_stops_working_immediately(): void
    {
        $agency = $this->tenant('Late Agency', 'agency');
        $brand = $this->tenant('Late Brand', 'brand');
        $user = $this->user($agency, 'late@test.dev');
        $this->grant($user, $agency, Portal::Agency);
        $appMembership = $this->grant($user, $brand, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $appMembership->getKey()])
            ->assertOk();

        $appMembership->forceFill(['status' => 'revoked'])->save();

        $this->actingAs($user, 'sanctum')->getJson('/__probe/app')->assertForbidden();
    }

    public function test_the_endpoints_require_authentication(): void
    {
        $this->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertUnauthorized();
        $this->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => 'x'])->assertUnauthorized();
    }

    /**
     * The portal chosen before signing in survives authentication — but only as a preference. A
     * visitor who asks for a portal they do not hold lands on their own, not on someone else's.
     */
    public function test_the_requested_portal_is_carried_through_authentication_when_held(): void
    {
        $agency = $this->tenant('Carry Agency', 'agency');
        $brand = $this->tenant('Carry Brand', 'brand');
        $user = $this->user($agency, 'carry@test.dev');
        $this->grant($user, $agency, Portal::Agency);
        $this->grant($user, $brand, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/auth/memberships?portal=app')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/auth/memberships?portal=agency')
            ->assertOk()->assertJsonPath('data.destination', '/agency');
    }

    public function test_asking_for_an_unheld_portal_lands_on_your_own(): void
    {
        $tenant = $this->tenant('Unheld Co', 'brand');
        $user = $this->user($tenant, 'unheld@test.dev');
        $this->grant($user, $tenant, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/auth/memberships?portal=agency')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');
    }

    /** A junk portal value is ignored rather than erroring or opening anything. */
    public function test_a_nonsense_portal_value_is_ignored(): void
    {
        $tenant = $this->tenant('Junk Co', 'brand');
        $user = $this->user($tenant, 'junk@test.dev');
        $this->grant($user, $tenant, Portal::App);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->getJson('/api/v1/auth/memberships?portal=../../etc/passwd')
            ->assertOk()->assertJsonPath('data.destination', '/app/dashboard');
    }
    // ---- request-scoped lifetime ----

    /**
     * The context must not survive the request that populated it. If it did, a second request that
     * resolves no membership would inherit the first one's tenant and run its queries against data
     * nobody granted it — the kind of leak that never fails loudly.
     */
    public function test_the_membership_context_does_not_leak_between_requests(): void
    {
        $tenant = $this->tenant('Leak Co', 'agency');
        $member = $this->user($tenant, 'member@test.dev');
        $this->grant($member, $tenant, Portal::Agency);

        $this->actingAs($member, 'sanctum')->getJson('/__probe/agency')
            ->assertOk()->assertJsonPath('tenant', (string) $tenant->id);

        // The context is torn down with the request, so nothing is left set for the next one.
        $this->assertFalse(app(MembershipContext::class)->has());
        $this->assertNull(app(TenantContext::class)->tenantId());
    }

    /** A second user in the same process must not inherit the first user's scope. */
    public function test_a_second_request_by_another_user_gets_its_own_scope(): void
    {
        $agency = $this->tenant('First Co', 'agency');
        $brand = $this->tenant('Second Co', 'brand');
        $first = $this->user($agency, 'first@test.dev');
        $second = $this->user($brand, 'second@test.dev');
        $this->grant($first, $agency, Portal::Agency);
        $this->grant($second, $brand, Portal::App);

        $this->actingAs($first, 'sanctum')->getJson('/__probe/agency')
            ->assertOk()->assertJsonPath('tenant', (string) $agency->id);

        $this->actingAs($second, 'sanctum')->getJson('/__probe/app')
            ->assertOk()->assertJsonPath('tenant', (string) $brand->id);
    }

    /**
     * The context is SCOPED, so everything resolved within one request shares one instance. A
     * singleton would have been shared too — but would also have outlived the request; a plain
     * binding would have handed each service its own empty copy, and the membership resolved in
     * middleware would be invisible to controllers and policies.
     */
    public function test_every_service_in_one_request_sees_the_same_context(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:app'])
            ->get('/__probe-identity', fn () => response()->json([
                'same' => app(MembershipContext::class)
                    === app(MembershipContext::class),
                'portal' => app(MembershipContext::class)->portal()?->value,
            ]));

        $tenant = $this->tenant('Identity Co', 'brand');
        $user = $this->user($tenant, 'identity@test.dev');
        $this->grant($user, $tenant, Portal::App);

        $this->actingAs($user, 'sanctum')->getJson('/__probe-identity')
            ->assertOk()
            ->assertJsonPath('same', true)
            ->assertJsonPath('portal', 'app');
    }
}
