<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Access\Support\MemberOverrideMemo;
use App\Domains\Bootstrap\Support\WorkspaceReadCache;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * PERF-BOOTSTRAP-001 — the per-tenant read cache of branding, subscription, access and the funding disclaimer (TTL 10 min).
 *
 * Held here: keys always carry the tenant (and the person, where the answer is a person's), so two tenants never see each other's
 * cached value; every write path of every section abandons its entries on the very next read; and a dead cache store fails
 * nothing.
 */
final class WorkspaceReadCacheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceReadCache::reset();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->a = $this->tenant('Agency A');
        $this->b = $this->tenant('Agency B');
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
    }

    /** @param list<string>|null $keys */
    private function member(Tenant $tenant, ?array $keys = null): User
    {
        $this->holdingTenant((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...($keys ?? Permission::pluck('key')->all()));
        $user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant, Portal::Agency);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $user->setRelation('fixtureRole', $role);

        return $user;
    }

    private function read(User $u, string $path): array
    {
        Sanctum::actingAs($u);

        return (array) $this->getJson($path)->assertOk()->json('data');
    }

    // ---- isolation ---------------------------------------------------------------------------------------------------------

    public function test_a_key_always_names_its_tenant_and_never_collides_across_tenants_or_people(): void
    {
        $k = fn (string $tenant, ?string $m = null, int|string|null $u = null): string => (string) WorkspaceReadCache::key(WorkspaceReadCache::ACCESS, $tenant, $m, $u);

        $this->assertStringContainsString(':t:'.$this->a->id, $k((string) $this->a->id));
        $this->assertNotSame($k((string) $this->a->id), $k((string) $this->b->id));
        $this->assertNotSame($k((string) $this->a->id, 'm1', 1), $k((string) $this->a->id, 'm1', 2), 'two people of one tenant');
        $this->assertNotSame($k((string) $this->a->id, 'm1', 1), $k((string) $this->a->id, 'm2', 1), 'two memberships of one person');
        // An answer that cannot be filed under a tenant is not cached at all.
        $calls = 0;
        $compute = function () use (&$calls): string {
            $calls++;

            return 'v';
        };
        WorkspaceReadCache::remember(WorkspaceReadCache::BRANDING, '', $compute);
        WorkspaceReadCache::remember(WorkspaceReadCache::BRANDING, '', $compute);
        $this->assertSame(2, $calls);
    }

    public function test_two_tenants_never_see_each_others_cached_branding_subscription_and_access(): void
    {
        $ua = $this->member($this->a);
        $ub = $this->member($this->b, ['notifications.view', 'subscriptions.view']);
        app(SubscriptionService::class)->assignPlan($this->a, SubscriptionPlan::where('code', 'growth')->firstOrFail());
        app(SubscriptionService::class)->assignPlan($this->b, SubscriptionPlan::where('code', 'starter')->firstOrFail());
        $this->holdingTenant((string) $this->a->id);
        $this->a->forceFill(['settings' => ['branding' => ['portal_name' => 'Alpha Co']]])->save();
        $this->holdingTenant((string) $this->b->id);
        $this->b->forceFill(['settings' => ['branding' => ['portal_name' => 'Beta Co']]])->save();

        // Warm both, then read again, interleaved: the second round is served from the cache.
        for ($round = 0; $round < 2; $round++) {
            foreach ([[$ua, 'Alpha Co', 'growth', true], [$ub, 'Beta Co', 'starter', false]] as [$user, $name, $plan, $isOwner]) {
                $this->assertSame($name, $this->read($user, '/api/v1/settings/branding')['branding']['portal_name'], "round {$round}");
                $this->assertSame($plan, $this->read($user, '/api/v1/subscriptions/current')['plan']['code'] ?? null, "round {$round}");
                $sections = $this->read($user, '/api/v1/me/access')['sections'];
                $this->assertSame($isOwner, in_array('budgets', $sections, true), "round {$round}: access sections of the right tenant's role");
            }
        }
    }

    // ---- invalidation: every write path ------------------------------------------------------------------------------------

    public function test_a_branding_save_is_read_back_at_once(): void
    {
        $u = $this->member($this->a);
        $this->assertSame('CampaignsHub', $this->read($u, '/api/v1/settings/branding')['branding']['portal_name']);

        Sanctum::actingAs($u);
        $this->putJson('/api/v1/settings/branding', ['branding' => ['primary_color' => '#123456', 'report_accent' => '#123456', 'portal_name' => 'Renamed']])->assertOk();

        $this->assertSame('Renamed', $this->read($u, '/api/v1/settings/branding')['branding']['portal_name']);
        // A write that bypasses the controller (a console edit, a job) is caught too — it is the SQL that is watched.
        DB::table('tenants')->where('id', $this->a->id)->update(['settings' => json_encode(['branding' => ['portal_name' => 'By SQL']])]);
        $this->assertSame('By SQL', $this->read($u, '/api/v1/settings/branding')['branding']['portal_name']);
    }

    public function test_a_plan_change_a_status_change_and_a_catalogue_edit_are_read_back_at_once(): void
    {
        $u = $this->member($this->a);
        $svc = app(SubscriptionService::class);
        $svc->assignPlan($this->a, SubscriptionPlan::where('code', 'starter')->firstOrFail());
        $this->assertSame('starter', $this->read($u, '/api/v1/subscriptions/current')['plan']['code']);

        $svc->assignPlan($this->a, SubscriptionPlan::where('code', 'growth')->firstOrFail());
        $this->assertSame('growth', $this->read($u, '/api/v1/subscriptions/current')['plan']['code']);

        DB::table('subscriptions')->where('tenant_id', $this->a->id)->update(['status' => 'past_due']);
        $this->assertSame('past_due', $this->read($u, '/api/v1/subscriptions/current')['subscription']['status']);

        $plan = SubscriptionPlan::where('code', 'growth')->firstOrFail();
        $plan->forceFill(['name' => 'Growth, renamed'])->save();
        $this->assertSame('Growth, renamed', $this->read($u, '/api/v1/subscriptions/current')['plan']['name']);
    }

    public function test_usage_is_never_served_from_the_cache(): void
    {
        $u = $this->member($this->a);
        app(SubscriptionService::class)->assignPlan($this->a, SubscriptionPlan::where('code', 'growth')->firstOrFail());
        $before = $this->read($u, '/api/v1/subscriptions/current')['usage']['clients']['used'];

        $this->holdingTenant((string) $this->a->id);
        ClientWorkspace::create(['tenant_id' => $this->a->id, 'name' => 'New client', 'slug' => 'nc-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);

        $this->assertSame($before + 1, $this->read($u, '/api/v1/subscriptions/current')['usage']['clients']['used'], 'the meter counts the new client at once');
    }

    public function test_access_follows_role_permissions_overrides_and_scopes_on_the_next_fetch(): void
    {
        $owner = $this->member($this->a);
        $member = $this->member($this->a, ['projects.view', 'clients.view']);
        /** @var Role $role */
        $role = $member->getRelation('fixtureRole');

        $this->assertNotContains('budgets', $this->read($member, '/api/v1/me/access')['sections']);

        // 1. A pivot sync — no model event fires for it.
        $role->permissions()->sync(Permission::whereIn('key', ['projects.view', 'clients.view', 'budget.view', 'funding.view'])->pluck('id')->all());
        $this->assertContains('budgets', $this->read($member, '/api/v1/me/access')['sections'], 'role gained budget keys');

        // 2. A member override (what the access center writes with DB::table): a section switched off.
        DB::table('member_access_overrides')->insert(['tenant_id' => $this->a->id, 'user_id' => $member->id,
            'permission_key' => 'section:budgets', 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]);
        app(MemberOverrideMemo::class)->forget();
        $this->assertNotContains('budgets', $this->read($member, '/api/v1/me/access')['sections'], 'override switched the section off');

        DB::table('member_access_overrides')->where('user_id', $member->id)->delete();
        app(MemberOverrideMemo::class)->forget();
        $this->assertContains('budgets', $this->read($member, '/api/v1/me/access')['sections'], 'override removed');

        // 3. A client ceiling on the membership (a scope row), then a role change (another role assigned).
        $client = ClientWorkspace::create(['tenant_id' => $this->a->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $membership = Membership::query()->where('user_id', $member->id)->firstOrFail();
        $before = $this->read($member, '/api/v1/me/access')['clients'];
        MembershipScope::create(['membership_id' => $membership->id, 'scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => (string) $client->id]);
        $after = $this->read($member, '/api/v1/me/access')['clients'];
        $this->assertNotSame($before, $after);
        $this->assertSame([(string) $client->id], $after['ids'], 'the client ceiling shows on the next fetch');

        $other = Role::create(['tenant_id' => $this->a->id, 'name' => 'None', 'slug' => 'none-'.uniqid()]);
        $member->roles()->sync([$other->id]);
        $this->assertSame([], $this->read($member, '/api/v1/me/access')['sections'], 'role replaced by one with no keys');

        // The owner's own cached answer was untouched by any of it, and still the owner's.
        $this->assertContains('budgets', $this->read($owner, '/api/v1/me/access')['sections']);
    }

    public function test_the_ttl_is_ten_minutes(): void
    {
        $this->assertSame(600, WorkspaceReadCache::TTL_SECONDS);
    }

    // ---- the cache store is down -------------------------------------------------------------------------------------------

    public function test_a_dead_cache_store_fails_nothing(): void
    {
        $u = $this->member($this->a);
        app(SubscriptionService::class)->assignPlan($this->a, SubscriptionPlan::where('code', 'growth')->firstOrFail());

        Cache::extend('broken', fn () => new Repository(new class implements Store
        {
            private function fail(): never
            {
                throw new RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }

            public function get($key)
            {
                $this->fail();
            }

            public function many(array $keys)
            {
                $this->fail();
            }

            public function put($key, $value, $seconds)
            {
                $this->fail();
            }

            public function putMany(array $values, $seconds)
            {
                $this->fail();
            }

            public function increment($key, $value = 1)
            {
                $this->fail();
            }

            public function decrement($key, $value = 1)
            {
                $this->fail();
            }

            public function forever($key, $value)
            {
                $this->fail();
            }

            public function forget($key)
            {
                $this->fail();
            }

            public function flush()
            {
                $this->fail();
            }

            public function getPrefix()
            {
                return '';
            }
        }));
        config(['cache.stores.broken' => ['driver' => 'broken'], 'cache.default' => 'broken']);
        Cache::forgetDriver();
        WorkspaceReadCache::reset();

        Sanctum::actingAs($u);
        $this->getJson('/api/v1/settings/branding')->assertOk()->assertJsonPath('data.branding.portal_name', 'CampaignsHub');
        $this->getJson('/api/v1/subscriptions/current')->assertOk()->assertJsonPath('data.plan.code', 'growth');
        $this->getJson('/api/v1/me/access')->assertOk();
        $this->getJson('/api/v1/funding/disclaimer')->assertOk();
        $data = $this->getJson('/api/v1/bootstrap')->assertOk()->json('data');
        foreach (['me', 'memberships', 'access', 'subscription', 'branding', 'notifications_summary', 'funding_disclaimer'] as $section) {
            $this->assertNotNull($data[$section], "{$section} still answers with the cache down");
        }

        // A write while the store is down must not throw either (the invalidator would be bumping an epoch it cannot reach).
        $this->putJson('/api/v1/settings/branding', ['branding' => ['primary_color' => '#123456', 'report_accent' => '#123456', 'portal_name' => 'Still saves']])->assertOk();
        $this->getJson('/api/v1/settings/branding')->assertOk()->assertJsonPath('data.branding.portal_name', 'Still saves');
    }
}
