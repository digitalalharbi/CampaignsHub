<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Requests\Support\RequestLabels;
use App\Domains\Taxonomy\Models\TaxonomyDefinition;
use App\Domains\Taxonomy\Models\TaxonomyOption;
use App\Domains\Tenancy\Context\MembershipContext;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Domains\Tenancy\Support\WorkspaceAssertion;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ENSURE-PORTAL-SESSION-001 — a request never decides which tenant a LATER request runs under.
 *
 * `EnsurePortal` used to write the membership it found into the session when a route of another portal was hit. Two
 * tabs share one session and the client sends no workspace of its own (`frontend/src/lib/api/client.ts` sends only
 * `Accept-Language` and the XSRF token), so the session IS the tenant of every request. One agency-only call therefore
 * re-pointed every following request from every tab, with nothing on any screen saying so.
 *
 * Each «tab» here is simply the next request on the same session, because that is all a tab is to the server.
 */
final class EnsurePortalSessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    private Tenant $brand;

    private Tenant $agency;

    private User $user;

    private Membership $appMembership;

    private Membership $agencyMembership;

    private Project $brandProject;

    private Project $agencyProject;

    private ClientWorkspace $brandClient;

    private ClientWorkspace $agencyClient;

    protected function setUp(): void
    {
        parent::setUp();

        // Carries nothing but the middleware under test: the tenant a request with no portal in its URL runs under.
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('/__probe/tenant-only', fn () => response()->json(['tenant' => app(TenantContext::class)->tenantId()]));

        // No real route is `portal:app` alone, and the reverse direction still has to be held.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:app'])
            ->get('/__probe/app-only', fn () => response()->json([
                'tenant' => app(TenantContext::class)->tenantId(),
                'portal' => app(MembershipContext::class)->portal()?->value,
            ]));

        // An answer cached the way the codebase caches one: the tenant the request resolved is part of the key.
        foreach (['app' => 'portal:app', 'agency' => 'portal:agency', 'shared' => 'portal:app,agency'] as $name => $gate) {
            Route::middleware(['api', 'auth:sanctum', 'tenant', $gate])
                ->get('/__probe/cached/'.$name, function () {
                    $tenant = (string) app(TenantContext::class)->tenantId();

                    return response()->json(['value' => Cache::remember('probe-answer:'.$tenant, 60, fn () => 'answer-of-'.$tenant)]);
                });
        }

        // `#webhook#` is on the review deny-list, so a review workspace must refuse this whichever way it was reached.
        Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:agency'])
            ->get('/__probe/webhook/agency', fn () => response()->json(['tenant' => app(TenantContext::class)->tenantId()]));

        $this->brand = $this->tenant('Brand Co', 'brand');
        $this->agency = $this->tenant('Agency Co', 'agency');
        $this->user = User::create(['name' => 'Both', 'email' => 'both@test.dev', 'password' => 'secret123']);
        $this->user->forceFill(['email_verified_at' => now()])->save();

        $this->appMembership = app(MembershipProvisioner::class)->ensure($this->user, $this->brand, Portal::App, 'owner');
        $this->agencyMembership = app(MembershipProvisioner::class)->ensure($this->user, $this->agency, Portal::Agency, 'owner');

        $this->seed(PermissionSeeder::class);
        foreach ([$this->brand, $this->agency] as $tenant) {
            $role = Role::create(['tenant_id' => $tenant->getKey(), 'name' => 'Owner', 'slug' => 'owner']);
            $role->givePermissionTo(...Permission::pluck('key')->all());
            $this->user->assignRole($role);
        }

        [$this->brandClient, $this->brandProject] = $this->clientAndProject($this->brand, 'Brand');
        [$this->agencyClient, $this->agencyProject] = $this->clientAndProject($this->agency, 'Agency');
    }

    private function tenant(string $name, string $type): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => str($name)->slug()->value(), 'status' => 'active', 'account_type' => $type,
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
    }

    /** @return array{0: ClientWorkspace, 1: Project} */
    private function clientAndProject(Tenant $tenant, string $name): array
    {
        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => $name.' Client', 'slug' => str($name)->slug()->value().'-client',
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => $name.' Project', 'status' => 'active',
        ]);

        return [$client, $project];
    }

    /** One browser: every call shares the session cookie, whichever tab made it. */
    private function browser(?User $as = null): self
    {
        return $this->actingAs($as ?? $this->user, 'web')->withHeaders($this->spaHeaders);
    }

    private function switchTo(Membership $membership): void
    {
        $this->browser()->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $membership->getKey()])
            ->assertOk();
    }

    /** What the session currently answers for a request with no portal in its URL, on every surface that reads it. */
    private function assertLaterRequestsRunUnder(Tenant $tenant, Project $own, Project $foreign): void
    {
        $this->browser()->getJson('/__probe/tenant-only')->assertOk()->assertJsonPath('tenant', (string) $tenant->getKey());
        $this->browser()->getJson('/api/v1/auth/memberships')->assertOk()->assertJsonPath('data.current.tenant.id', (string) $tenant->getKey());

        $projects = $this->browser()->getJson('/api/v1/projects')->assertOk()->json('data');
        $ids = array_column($projects, 'id');
        $this->assertContains((string) $own->getKey(), $ids);
        $this->assertNotContains((string) $foreign->getKey(), $ids, 'A shared route answered with the other tab\'s tenant.');
    }

    public function test_an_agency_call_from_one_tab_does_not_move_the_other_tabs_tenant(): void
    {
        $this->switchTo($this->appMembership);
        $this->assertLaterRequestsRunUnder($this->brand, $this->brandProject, $this->agencyProject);

        // Tab two, in the agency portal. It is served from the agency and says so…
        $clients = $this->browser()->getJson('/api/v1/client-workspaces')->assertOk()->json('data');
        $this->assertSame([(string) $this->agencyClient->getKey()], array_column($clients, 'id'));

        // …and tab one's very next requests are still the advertiser's.
        $this->assertLaterRequestsRunUnder($this->brand, $this->brandProject, $this->agencyProject);
    }

    public function test_the_same_holds_in_the_other_direction(): void
    {
        $this->switchTo($this->agencyMembership);
        $this->assertLaterRequestsRunUnder($this->agency, $this->agencyProject, $this->brandProject);

        $this->browser()->getJson('/__probe/app-only')->assertOk()
            ->assertJsonPath('tenant', (string) $this->brand->getKey())
            ->assertJsonPath('portal', 'app');

        $this->assertLaterRequestsRunUnder($this->agency, $this->agencyProject, $this->brandProject);
    }

    /** Without an explicit choice the default is the session's answer, and a portal route must not supply a new one. */
    public function test_a_session_that_never_chose_stays_on_its_default(): void
    {
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        $this->assertLaterRequestsRunUnder($this->brand, $this->brandProject, $this->agencyProject);
    }

    public function test_interleaved_tabs_each_get_their_own_answer_every_time(): void
    {
        $this->switchTo($this->appMembership);

        foreach (range(1, 3) as $round) {
            $this->browser()->getJson('/api/v1/client-workspaces')->assertOk()
                ->assertJsonPath('data.0.id', (string) $this->agencyClient->getKey());
            $this->browser()->getJson('/__probe/tenant-only')->assertOk()->assertJsonPath('tenant', (string) $this->brand->getKey());
            $this->browser()->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.id', (string) $this->brandProject->getKey());
        }
    }

    /**
     * «Last used» orders the memberships a default is taken from, so writing it from a read is a second way a request
     * could steer a later one. Only somebody choosing may write it.
     */
    public function test_a_portal_route_records_nothing_about_the_membership_it_used(): void
    {
        $this->assertNull($this->agencyMembership->fresh()->last_used_at);

        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();
        $this->assertNull($this->agencyMembership->fresh()->last_used_at);

        $this->switchTo($this->agencyMembership);
        $this->assertNotNull($this->agencyMembership->fresh()->last_used_at);
    }

    // ---- what must keep working ----

    public function test_the_explicit_switch_still_moves_every_later_request(): void
    {
        $this->switchTo($this->appMembership);
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();
        $this->assertLaterRequestsRunUnder($this->brand, $this->brandProject, $this->agencyProject);

        $this->switchTo($this->agencyMembership);
        $this->assertLaterRequestsRunUnder($this->agency, $this->agencyProject, $this->brandProject);

        $this->browser()->getJson('/__probe/app-only')->assertOk();
        $this->assertLaterRequestsRunUnder($this->agency, $this->agencyProject, $this->brandProject);
    }

    /** A bearer token has no session to remember a choice in: this is the only way it reaches its second portal. */
    public function test_a_stateless_client_still_reaches_either_portal_it_holds(): void
    {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/client-workspaces')->assertOk()
            ->assertJsonPath('data.0.id', (string) $this->agencyClient->getKey());
        $this->actingAs($this->user, 'sanctum')->getJson('/__probe/app-only')->assertOk()
            ->assertJsonPath('tenant', (string) $this->brand->getKey());
    }

    public function test_the_post_auth_destination_is_unchanged_by_a_portal_route(): void
    {
        $before = $this->browser()->getJson('/api/v1/auth/memberships?portal=agency')->assertOk()->json('data.destination');
        $this->assertSame('/agency', $before);

        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        $this->browser()->getJson('/api/v1/auth/memberships?portal=agency')->assertOk()->assertJsonPath('data.destination', $before);
        $this->browser()->getJson('/api/v1/auth/memberships')->assertOk()->assertJsonPath('data.destination', '/switch');
    }

    // ---- denial ----

    public function test_a_row_of_the_other_tenant_stays_out_of_reach_after_the_other_portal_was_visited(): void
    {
        $this->switchTo($this->appMembership);
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        // The session's own tenant: the agency's project does not exist here.
        $this->browser()->getJson('/api/v1/projects/'.$this->agencyProject->getKey())->assertNotFound();
        $this->browser()->getJson('/api/v1/projects/'.$this->brandProject->getKey())->assertOk();

        // The agency route's own tenant: the advertiser's client does not exist there, however the session stands.
        $this->browser()->getJson('/api/v1/client-workspaces/'.$this->brandClient->getKey())->assertNotFound();
        $this->browser()->getJson('/api/v1/client-workspaces/'.$this->agencyClient->getKey())->assertOk();
    }

    /** A write after the visit goes to the workspace the session names, and cannot be pointed at a row from the other one. */
    public function test_a_write_after_the_other_portal_was_visited_cannot_touch_the_other_tenant(): void
    {
        $this->switchTo($this->appMembership);
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        $this->browser()->patchJson('/api/v1/projects/'.$this->agencyProject->getKey(), ['name' => 'Renamed'])->assertNotFound();
        $this->assertSame('Agency Project', $this->agencyProject->fresh()->name);

        $this->browser()->patchJson('/api/v1/projects/'.$this->brandProject->getKey(), ['name' => 'Renamed'])->assertOk();
        $this->assertSame('Renamed', $this->brandProject->fresh()->name);
    }

    public function test_somebody_who_does_not_hold_the_portal_is_refused_and_nothing_moves(): void
    {
        $stranger = User::create(['name' => 'App only', 'email' => 'apponly@test.dev', 'password' => 'secret123']);
        $stranger->forceFill(['email_verified_at' => now()])->save();
        app(MembershipProvisioner::class)->ensure($stranger, $this->brand, Portal::App, 'owner');

        $this->browser($stranger)->getJson('/api/v1/client-workspaces')->assertForbidden();
        $this->browser($stranger)->getJson('/__probe/webhook/agency')->assertForbidden();

        $this->browser($stranger)->getJson('/__probe/tenant-only')->assertOk()->assertJsonPath('tenant', (string) $this->brand->getKey());
    }

    /** A membership revoked since the session chose it falls back, and does not carry a portal route into its tenant. */
    public function test_a_revoked_membership_opens_nothing_through_the_other_portals_route(): void
    {
        $this->switchTo($this->appMembership);
        $this->agencyMembership->forceFill(['status' => 'revoked'])->save();

        $this->browser()->getJson('/api/v1/client-workspaces')->assertForbidden();
        $this->browser()->getJson('/__probe/tenant-only')->assertOk()->assertJsonPath('tenant', (string) $this->brand->getKey());
    }

    /**
     * SALLA-REVIEW-001 is enforced where the tenant is resolved, and for this request `EnsurePortal` is that place.
     * Skipping it would have turned «the session stays where it is» into a permanent way around every restriction.
     */
    public function test_a_review_workspace_keeps_its_restrictions_when_reached_through_a_portal_route(): void
    {
        $this->agency->forceFill(['settings' => ['review' => ['active' => true, 'expires_at' => now()->addDay()->toIso8601String()]]])->save();
        $this->switchTo($this->appMembership);

        foreach (range(1, 2) as $attempt) {
            $this->browser()->getJson('/__probe/webhook/agency')->assertForbidden();
        }

        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();
    }

    // ---- cache ----

    public function test_a_cached_answer_keyed_by_tenant_is_not_read_across_tabs(): void
    {
        $this->switchTo($this->appMembership);

        $own = 'answer-of-'.$this->brand->getKey();
        $other = 'answer-of-'.$this->agency->getKey();

        foreach (range(1, 2) as $round) {
            $this->browser()->getJson('/__probe/cached/shared')->assertOk()->assertJsonPath('value', $own);
            $this->browser()->getJson('/__probe/cached/agency')->assertOk()->assertJsonPath('value', $other);
            $this->browser()->getJson('/__probe/cached/shared')->assertOk()->assertJsonPath('value', $own);
            $this->browser()->getJson('/__probe/cached/app')->assertOk()->assertJsonPath('value', $own);
        }
    }

    // ---- a route alone never moves the persistent selection (#945) ----

    private function selection(): mixed
    {
        return session('membership_id');
    }

    public function test_no_route_of_any_portal_changes_the_persistent_selection(): void
    {
        $this->switchTo($this->appMembership);
        $chosen = (string) $this->appMembership->getKey();
        $this->assertSame($chosen, $this->selection());

        foreach (['/api/v1/client-workspaces', '/__probe/cached/agency', '/__probe/cached/app', '/api/v1/projects', '/api/v1/auth/memberships?portal=agency', '/__probe/tenant-only'] as $route) {
            $this->browser()->getJson($route)->assertOk();
            $this->assertSame($chosen, $this->selection(), $route.' moved the session.');
        }
    }

    public function test_a_session_with_no_selection_gets_none_from_a_route(): void
    {
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        $this->assertNull($this->selection());
    }

    public function test_only_a_membership_the_person_holds_can_be_selected_and_only_by_the_switch(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => 'stranger@test.dev', 'password' => 'secret123']);
        $theirs = app(MembershipProvisioner::class)->ensure($stranger, $this->tenant('Their Co', 'agency'), Portal::Agency, 'owner');

        $this->switchTo($this->appMembership);
        $this->browser()->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $theirs->getKey()])->assertForbidden();

        $this->assertSame((string) $this->appMembership->getKey(), $this->selection());
    }

    public function test_a_request_served_for_itself_says_so_and_an_ordinary_one_does_not(): void
    {
        $this->switchTo($this->appMembership);

        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk()->assertHeader('X-Portal-Context', 'request');
        $this->browser()->getJson('/api/v1/projects')->assertOk()->assertHeaderMissing('X-Portal-Context');
    }

    // ---- two tabs, one of them stale ----

    /** The tab that was drawn for this membership, and says so on its requests. */
    private function tab(Membership $drawnFor): self
    {
        return $this->browser()->withHeader(WorkspaceAssertion::HEADER, (string) $drawnFor->getKey());
    }

    private function assertRefusedAsChanged(mixed $response): void
    {
        $response->assertStatus(409)->assertJsonPath('code', 'workspace_changed');
    }

    public function test_a_stale_tab_reads_nothing_of_the_new_workspace(): void
    {
        $this->switchTo($this->appMembership);
        $this->tab($this->appMembership)->getJson('/api/v1/projects')->assertOk();

        // The other tab chooses, and this one never hears about it.
        $this->switchTo($this->agencyMembership);

        foreach (['/api/v1/projects', '/api/v1/projects/'.$this->agencyProject->getKey(), '/__probe/tenant-only', '/__probe/cached/shared'] as $route) {
            $response = $this->tab($this->appMembership)->getJson($route);
            $this->assertRefusedAsChanged($response);
            $this->assertStringNotContainsString((string) $this->agency->getKey(), (string) $response->getContent());
            $this->assertStringNotContainsString('Agency Project', (string) $response->getContent());
        }
    }

    public function test_a_write_from_a_stale_tab_lands_nowhere(): void
    {
        $this->switchTo($this->appMembership);
        $this->switchTo($this->agencyMembership);

        foreach ([$this->agencyProject, $this->brandProject] as $project) {
            $this->assertRefusedAsChanged($this->tab($this->appMembership)->patchJson('/api/v1/projects/'.$project->getKey(), ['name' => 'Stale write']));
        }

        $this->assertSame('Agency Project', $this->agencyProject->fresh()->name);
        $this->assertSame('Brand Project', $this->brandProject->fresh()->name);
    }

    public function test_the_tab_that_chose_keeps_working_beside_the_stale_one(): void
    {
        $this->switchTo($this->appMembership);
        $this->switchTo($this->agencyMembership);

        foreach (range(1, 3) as $round) {
            $this->assertRefusedAsChanged($this->tab($this->appMembership)->getJson('/api/v1/projects'));
            $this->tab($this->agencyMembership)->patchJson('/api/v1/projects/'.$this->agencyProject->getKey(), ['name' => 'Round '.$round])->assertOk();
        }

        $this->assertSame('Round 3', $this->agencyProject->fresh()->name);
        $this->assertSame((string) $this->agencyMembership->getKey(), $this->selection());
    }

    /** A stale tab has to be able to find out where it is, choose, and leave. */
    public function test_a_stale_tab_can_still_read_the_list_switch_and_sign_out(): void
    {
        $this->switchTo($this->appMembership);
        $this->switchTo($this->agencyMembership);

        $this->tab($this->appMembership)->getJson('/api/v1/auth/memberships')->assertOk()
            ->assertJsonPath('data.current.tenant.id', (string) $this->agency->getKey());
        $this->tab($this->appMembership)->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $this->appMembership->getKey()])->assertOk();
        $this->tab($this->appMembership)->getJson('/api/v1/projects')->assertOk();
    }

    /** It can refuse, and can do nothing else: naming a workspace does not put a request in it. */
    public function test_the_claim_selects_nothing_and_a_forged_one_gains_nothing(): void
    {
        $stranger = User::create(['name' => 'S', 'email' => 'forger-target@test.dev', 'password' => 'secret123']);
        $theirs = app(MembershipProvisioner::class)->ensure($stranger, $this->tenant('Target Co', 'agency'), Portal::Agency, 'owner');

        $this->switchTo($this->appMembership);

        foreach ([(string) $this->agencyMembership->getKey(), (string) $theirs->getKey(), 'not-a-uuid'] as $claim) {
            $this->assertRefusedAsChanged($this->browser()->withHeader(WorkspaceAssertion::HEADER, $claim)->getJson('/api/v1/client-workspaces'));
            $this->assertRefusedAsChanged($this->browser()->withHeader(WorkspaceAssertion::HEADER, $claim)->getJson('/api/v1/projects'));
            $this->assertSame((string) $this->appMembership->getKey(), $this->selection());
        }
    }

    /** A tab drawn for the advertiser asks the agency's route: refused like somebody not holding it, however they got there. */
    public function test_an_advertiser_screen_cannot_fetch_the_agencys_data(): void
    {
        $this->switchTo($this->appMembership);

        $response = $this->tab($this->appMembership)->getJson('/api/v1/client-workspaces')->assertForbidden();

        $this->assertStringNotContainsString('Agency Client', (string) $response->getContent());
        $this->tab($this->appMembership)->postJson('/api/v1/client-workspaces', ['name' => 'Wrong', 'mode' => 'managed'])->assertForbidden();
        $this->assertSame(0, ClientWorkspace::query()->withoutGlobalScopes()->where('name', 'Wrong')->count());
        $this->assertSame((string) $this->appMembership->getKey(), $this->selection());
    }

    // ---- role and portal scopes ----

    /** Permissions are those of the tenant the request runs in, so the owner of one workspace is not the owner of the other. */
    public function test_a_route_served_for_itself_has_the_role_of_that_tenant_not_of_the_session(): void
    {
        $this->user->roles()->detach(Role::query()->withoutGlobalScopes()->where('tenant_id', $this->agency->getKey())->pluck('id'));

        $this->switchTo($this->appMembership);
        $this->browser()->getJson('/api/v1/projects')->assertOk();
        $this->browser()->getJson('/api/v1/client-workspaces')->assertForbidden();
        $this->assertLaterRequestsRunUnder($this->brand, $this->brandProject, $this->agencyProject);
    }

    public function test_a_route_served_for_itself_keeps_that_memberships_client_ceiling(): void
    {
        $second = ClientWorkspace::create([
            'tenant_id' => $this->agency->getKey(), 'name' => 'Out Of Scope', 'slug' => 'out-of-scope',
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        MembershipScope::create(['membership_id' => $this->agencyMembership->getKey(), 'scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => $this->agencyClient->getKey()]);

        $this->switchTo($this->appMembership);

        $ids = array_column($this->browser()->getJson('/api/v1/client-workspaces')->assertOk()->json('data'), 'id');
        $this->assertSame([(string) $this->agencyClient->getKey()], $ids);
        $this->browser()->getJson('/api/v1/client-workspaces/'.$second->getKey())->assertNotFound();
    }

    public function test_a_portal_nobody_holds_stays_shut_with_no_claim_and_with_one(): void
    {
        $this->switchTo($this->appMembership);

        Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:influencers'])->get('/__probe/influencers', fn () => response()->json(['ok' => true]));

        $this->browser()->getJson('/__probe/influencers')->assertForbidden();
        $this->tab($this->appMembership)->getJson('/__probe/influencers')->assertForbidden();
    }

    // ---- client reports and exports ----

    private function report(Project $project): Report
    {
        return Report::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'project_id' => $project->getKey(), 'name' => 'R-'.$project->name, 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'campaign_objective' => 'sales', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
        ]);
    }

    /** A link or a download carries no claim, so the project in its URL has to hold on its own. */
    public function test_another_tenants_report_and_its_export_stay_out_of_reach_after_the_other_portal_was_visited(): void
    {
        $foreign = $this->report($this->agencyProject);
        $own = $this->report($this->brandProject);
        $base = '/api/v1/projects/'.$this->agencyProject->getKey().'/reports';

        $this->switchTo($this->appMembership);
        $this->browser()->getJson('/api/v1/client-workspaces')->assertOk();

        $this->browser()->getJson($base)->assertNotFound();
        $this->browser()->getJson($base.'/'.$foreign->getKey())->assertNotFound();
        $this->browser()->postJson($base.'/'.$foreign->getKey().'/export', ['format' => 'csv'])->assertNotFound();

        // The other tenant's report through this tenant's project is no way in either.
        $mine = '/api/v1/projects/'.$this->brandProject->getKey().'/reports/';
        $this->browser()->getJson($mine.$foreign->getKey())->assertNotFound();
        $this->browser()->getJson($mine.$own->getKey())->assertOk();

        $this->assertSame(0, $foreign->exports()->count());
    }

    public function test_a_stale_tab_cannot_ask_for_an_export_of_either_tenant(): void
    {
        $brand = $this->report($this->brandProject);
        $agency = $this->report($this->agencyProject);

        $this->switchTo($this->appMembership);
        $this->switchTo($this->agencyMembership);

        foreach ([[$this->brandProject, $brand], [$this->agencyProject, $agency]] as [$project, $report]) {
            $this->assertRefusedAsChanged($this->tab($this->appMembership)
                ->postJson('/api/v1/projects/'.$project->getKey().'/reports/'.$report->getKey().'/export', ['format' => 'csv']));
            $this->assertSame(0, $report->exports()->count());
        }
    }

    // ---- RequestLabels' fixed cache key ----

    /** A fixed key is only safe if what is under it belongs to nobody: a tenant's own option must never be read into it. */
    public function test_request_labels_cache_holds_platform_options_and_never_a_tenants(): void
    {
        RequestLabels::forget();
        $definition = TaxonomyDefinition::withoutGlobalScopes()->create([
            'key' => 'request.priority', 'module' => 'requests', 'scope' => 'platform', 'field_type' => 'select',
            'label_ar' => 'الأولوية', 'label_en' => 'Priority', 'tenant_id' => null,
        ]);
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $definition->getKey(), 'key' => 'urgent', 'label_ar' => 'عاجل', 'label_en' => 'Urgent', 'tenant_id' => null,
        ]);
        TaxonomyOption::withoutGlobalScopes()->create([
            'taxonomy_definition_id' => $definition->getKey(), 'key' => 'agency-secret', 'label_ar' => 'سر', 'label_en' => 'Agency secret', 'tenant_id' => $this->agency->getKey(),
        ]);

        app(TenantContext::class)->setTenantId((string) $this->agency->getKey());
        $seenByTheOwner = RequestLabels::all();
        app(TenantContext::class)->setTenantId((string) $this->brand->getKey());
        $seenByAnother = RequestLabels::all();
        app(TenantContext::class)->forget();

        $this->assertSame(['urgent'], array_keys($seenByTheOwner));
        $this->assertSame($seenByTheOwner, $seenByAnother);
        RequestLabels::forget();
    }
}
