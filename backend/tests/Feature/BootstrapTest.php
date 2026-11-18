<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\MemberOverrideMemo;
use App\Domains\Bootstrap\Http\Controllers\BootstrapController;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PERF-BOOTSTRAP-001 — GET /api/v1/bootstrap folds the seven calls every page load starts with into one.
 *
 * Held here: the shape; that each section is byte-equal to the endpoint it replaces; that each is gated exactly as that
 * endpoint (portal, permission, section switch) and left out — never leaked — when the caller may not read it; and that one
 * tenant's answer never reaches another's.
 */
final class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    /** section => the endpoint it stands for */
    private const PATHS = [
        'me' => '/api/v1/auth/me',
        'memberships' => '/api/v1/auth/memberships',
        'access' => '/api/v1/me/access',
        'subscription' => '/api/v1/subscriptions/current',
        'branding' => '/api/v1/settings/branding',
        'notifications_summary' => '/api/v1/notifications/summary',
        'funding_disclaimer' => '/api/v1/funding/disclaimer',
    ];

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->tenantA = $this->tenant('Agency A');
        $this->tenantB = $this->tenant('Agency B');
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
    }

    /** @param list<string>|null $keys null = every permission */
    private function member(Tenant $tenant, ?array $keys = null, Portal $portal = Portal::Agency): User
    {
        $this->holdingTenant((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...($keys ?? Permission::pluck('key')->all()));
        $user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant, $portal);
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    /** @return array<string, mixed> the decoded `data` of the bootstrap, objects kept as objects */
    private function bootstrap(User $user): object
    {
        Sanctum::actingAs($user);

        return json_decode($this->getJson('/api/v1/bootstrap')->assertOk()->getContent())->data;
    }

    /** The `data` of an individual endpoint, as the encoded text a client would read. */
    private function endpointData(User $user, string $path): ?string
    {
        Sanctum::actingAs($user);
        $response = $this->getJson($path);

        return $response->getStatusCode() === 200 ? json_encode(json_decode($response->getContent())->data) : null;
    }

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/bootstrap')->assertUnauthorized();
    }

    public function test_an_agency_owner_gets_every_section_and_each_equals_its_own_endpoint(): void
    {
        $owner = $this->member($this->tenantA);
        app(SubscriptionService::class)->assignPlan($this->tenantA, SubscriptionPlan::where('code', 'growth')->firstOrFail());

        $data = $this->bootstrap($owner);
        $this->assertSame(array_keys(self::PATHS), array_keys((array) $data), 'shape: the seven sections, in order');

        foreach (self::PATHS as $section => $path) {
            $this->assertNotNull($data->{$section}, "{$section} must be present for an agency owner");
            $this->assertSame(
                $this->endpointData($owner, $path),
                json_encode($data->{$section}),
                "{$section} must be byte-equal to GET {$path}",
            );
        }
    }

    public function test_tenant_scoping_branding_and_subscription_never_cross_tenants(): void
    {
        $this->holdingTenant((string) $this->tenantA->id);
        $a = $this->member($this->tenantA);
        $b = $this->member($this->tenantB);
        app(SubscriptionService::class)->assignPlan($this->tenantA, SubscriptionPlan::where('code', 'growth')->firstOrFail());
        app(SubscriptionService::class)->assignPlan($this->tenantB, SubscriptionPlan::where('code', 'starter')->firstOrFail());

        Sanctum::actingAs($a);
        $this->putJson('/api/v1/settings/branding', ['branding' => ['primary_color' => '#111111', 'report_accent' => '#111111', 'portal_name' => 'Alpha Portal']])->assertOk();
        Sanctum::actingAs($b);
        $this->putJson('/api/v1/settings/branding', ['branding' => ['primary_color' => '#222222', 'report_accent' => '#222222', 'portal_name' => 'Beta Portal']])->assertOk();

        // Interleaved on purpose: A, B, A, B — a shared key would show the other's value on the second read.
        foreach ([$a, $b, $a, $b] as $i => $user) {
            $data = $this->bootstrap($user);
            $isA = $user->is($a);
            $this->assertSame($isA ? 'Alpha Portal' : 'Beta Portal', $data->branding->branding->portal_name, "read {$i}");
            $this->assertSame($isA ? 'growth' : 'starter', $data->subscription->plan->code, "read {$i}");
            $this->assertStringNotContainsString($isA ? 'Beta Portal' : 'Alpha Portal', json_encode($data));
        }
    }

    public function test_a_section_the_caller_may_not_read_is_null_and_its_endpoint_refuses(): void
    {
        // No `subscriptions.view`: the subscription endpoint answers 403, so the section is null.
        $limited = $this->member($this->tenantA, ['notifications.view', 'projects.view']);
        $data = $this->bootstrap($limited);

        $this->assertNull($data->subscription);
        $this->assertNull($this->endpointData($limited, self::PATHS['subscription']));
        // …and what it MAY read is still there.
        $this->assertNotNull($data->me);
        $this->assertNotNull($data->access);
        $this->assertNotNull($data->branding);
        $this->assertNotNull($data->funding_disclaimer);
        $this->assertStringNotContainsString('"plan"', json_encode($data), 'nothing of the subscription leaks into a null section');
    }

    public function test_a_switched_off_section_takes_the_funding_disclaimer_with_it(): void
    {
        $member = $this->member($this->tenantA);
        $this->assertNotNull($this->bootstrap($member)->funding_disclaimer);

        // The access center's «budgets» switch: a deny row. Every `api.v1.funding.*` route answers 403 from then on.
        DB::table('member_access_overrides')->insert(['tenant_id' => $this->tenantA->id, 'user_id' => $member->id,
            'permission_key' => 'section:budgets', 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]);
        // The access center forgets its per-request memo on a write; a test process is one long request, so it must too.
        app(MemberOverrideMemo::class)->forget();

        $this->assertNull($this->endpointData($member, self::PATHS['funding_disclaimer']), 'the endpoint itself refuses');
        $this->assertNull($this->bootstrap($member)->funding_disclaimer, 'so does the bootstrap — immediately, cache or not');
    }

    public function test_a_client_portal_member_gets_only_what_the_portal_routes_allow(): void
    {
        $client = $this->member($this->tenantA, [], Portal::ClientPortal);
        $data = $this->bootstrap($client);

        foreach (['branding', 'subscription', 'funding_disclaimer'] as $section) {
            $this->assertNull($data->{$section}, "{$section} is an app/agency route");
            $this->assertNull($this->endpointData($client, self::PATHS[$section]), 'GET '.self::PATHS[$section].' is refused to the portal');
        }
        foreach (['me', 'memberships', 'access', 'notifications_summary'] as $section) {
            $this->assertNotNull($data->{$section}, "{$section} is open to every membership");
            $this->assertSame($this->endpointData($client, self::PATHS[$section]), json_encode($data->{$section}));
        }
    }

    public function test_every_section_is_gated_like_the_route_it_replaces(): void
    {
        foreach (BootstrapController::ENDPOINTS as $section => [$routeName, $portals]) {
            $route = app('router')->getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "{$section}: route {$routeName} must exist");

            $gate = collect(app('router')->gatherRouteMiddleware($route))->first(fn ($m) => is_string($m) && str_contains($m, 'EnsurePortal'));
            if ($portals === null) {
                $this->assertNull($gate, "{$section}: the route names no portal, so the section must not either");

                continue;
            }
            $this->assertNotNull($gate, "{$section}: the route is portal-gated");
            $this->assertEqualsCanonicalizing(
                explode(',', substr((string) $gate, strpos((string) $gate, ':') + 1)),
                array_map(static fn (Portal $p): string => $p->value, $portals),
                "{$section}: portals must be those of {$routeName}",
            );
        }
    }
}
