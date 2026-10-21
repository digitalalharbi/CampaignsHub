<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CAP-REGISTRY-001 — one answer to «is this module available here?».
 *
 * The cases are about the two ways this can go wrong in a product that is sold in parts: a module that
 * switches itself on (advanced tracking and attribution must not), and a switch that reaches past its own
 * workspace or can be used to acquire a module that was never bought.
 */
final class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /** @param list<string> $modules @return array{0: Tenant, 1: User, 2: ClientWorkspace, 3: Project} */
    private function workspace(array $modules = [], bool $manager = true): array
    {
        $tenant = Tenant::create(['name' => 'W', 'slug' => 'cap-'.uniqid(), 'status' => 'active', 'enabled_modules' => $modules === [] ? null : $modules]);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $manager ? 'Owner' : 'Viewer', 'slug' => $manager ? 'tenant-owner' : 'viewer-'.uniqid()]);
        if ($manager) {
            $role->givePermissionTo(...Permission::pluck('key')->all());
        }
        $user = User::create(['name' => 'U', 'email' => uniqid().'@cap.test', 'password' => 'secret1234']);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: $manager ? 'owner' : 'viewer'));
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();

        return [$tenant, $user, $client, $project];
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum')->withHeaders(self::SPA);
    }

    /** @return array<string, array<string, mixed>> */
    private function states(Tenant $tenant, ?string $client = null, ?string $project = null): array
    {
        return array_map(fn ($s) => $s->toArray(), app(Capabilities::class)->resolve($tenant, $client, $project));
    }

    public function test_the_modules_a_workspace_already_had_stay_on_and_the_new_ones_are_off_and_unentitled(): void
    {
        [$tenant] = $this->workspace();
        $s = $this->states($tenant);

        foreach (['advertising_management', 'campaign_analytics', 'premium_client_reports', 'commerce_intelligence', 'ga4_intelligence', 'smart_links'] as $kept) {
            $this->assertTrue($s[$kept]['enabled'], $kept.' was taken from a workspace that had it');
        }
        foreach (['attribution_intelligence', 'marketing_impact', 'link_in_bio', 'advanced_tracking', 'influencer_intelligence'] as $new) {
            $this->assertFalse($s[$new]['enabled'], $new);
            $this->assertSame('not_entitled', $s[$new]['reason'], $new);
        }
    }

    public function test_an_entitled_advanced_module_still_does_not_switch_itself_on(): void
    {
        [$tenant] = $this->workspace(['paid_media', 'attribution_intelligence']);
        $s = $this->states($tenant)['attribution_intelligence'];

        $this->assertTrue($s['entitled']);
        $this->assertFalse($s['enabled']);
        $this->assertSame('not_activated', $s['reason']);
    }

    public function test_the_legacy_influencer_module_entitles_the_new_name(): void
    {
        [$tenant] = $this->workspace(['paid_media', 'influencer_marketing']);

        $this->assertTrue($this->states($tenant)['influencer_intelligence']['enabled']);
    }

    public function test_switches_are_read_top_down_and_an_off_above_is_final(): void
    {
        [$tenant, $user, $client, $project] = $this->workspace(['paid_media', 'attribution_intelligence']);
        $as = $this->as($user);

        $as->putJson('/api/v1/settings/capabilities/attribution_intelligence', ['scope' => 'tenant', 'enabled' => true])->assertOk();
        $this->assertTrue($this->states($tenant, (string) $client->id, (string) $project->id)['attribution_intelligence']['enabled']);

        // The project turns it off: off there, on for the rest.
        $as->putJson('/api/v1/settings/capabilities/attribution_intelligence', ['scope' => 'project', 'scope_id' => $project->id, 'enabled' => false])->assertOk();
        $off = $this->states($tenant, (string) $client->id, (string) $project->id)['attribution_intelligence'];
        $this->assertFalse($off['enabled']);
        $this->assertSame('project_off', $off['reason']);
        $this->assertTrue($this->states($tenant)['attribution_intelligence']['enabled']);

        // The client turns it off: a project cannot turn it back on beneath that.
        $as->putJson('/api/v1/settings/capabilities/attribution_intelligence', ['scope' => 'client', 'scope_id' => $client->id, 'enabled' => false])->assertOk();
        $as->putJson('/api/v1/settings/capabilities/attribution_intelligence', ['scope' => 'project', 'scope_id' => $project->id, 'enabled' => true])->assertOk();
        $locked = $this->states($tenant, (string) $client->id, (string) $project->id)['attribution_intelligence'];
        $this->assertFalse($locked['enabled']);
        $this->assertSame('client_off', $locked['reason']);

        // Clearing the switches goes back to inheriting.
        $as->deleteJson('/api/v1/settings/capabilities/attribution_intelligence', ['scope' => 'client', 'scope_id' => $client->id])->assertOk();
        $this->assertTrue($this->states($tenant, (string) $client->id, (string) $project->id)['attribution_intelligence']['enabled']);
    }

    public function test_a_switch_cannot_be_used_to_acquire_a_module(): void
    {
        [, $user] = $this->workspace();

        $this->as($user)->putJson('/api/v1/settings/capabilities/link_in_bio', ['scope' => 'tenant', 'enabled' => true])
            ->assertStatus(422)->assertJsonPath('errors.module.0', 'not_entitled');
    }

    public function test_changing_a_switch_needs_settings_manage_and_is_audited(): void
    {
        [, $viewer] = $this->workspace(['paid_media', 'marketing_impact'], manager: false);
        $this->as($viewer)->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'tenant', 'enabled' => true])->assertForbidden();
        $this->as($viewer)->getJson('/api/v1/settings/capabilities')->assertOk();

        [$tenant, $owner] = $this->workspace(['paid_media', 'marketing_impact']);
        $this->as($owner)->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'tenant', 'enabled' => true])->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'capability.changed')->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('marketing_impact', $log->entity_id);
        $this->assertTrue($log->after['enabled']);
    }

    public function test_another_workspaces_client_or_project_cannot_be_switched_or_read(): void
    {
        [, $owner] = $this->workspace(['paid_media', 'marketing_impact']);
        [, , $theirClient, $theirProject] = $this->workspace();
        $as = $this->as($owner);

        $as->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'client', 'scope_id' => $theirClient->id, 'enabled' => true])->assertNotFound();
        $as->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'project', 'scope_id' => $theirProject->id, 'enabled' => true])->assertNotFound();
        $as->getJson('/api/v1/settings/capabilities?project_id='.$theirProject->id)->assertNotFound();
        $as->getJson('/api/v1/settings/capabilities?client_id='.$theirClient->id)->assertNotFound();
    }

    public function test_one_workspaces_switch_never_reaches_another(): void
    {
        [$a, $ownerA] = $this->workspace(['paid_media', 'marketing_impact']);
        [$b] = $this->workspace(['paid_media', 'marketing_impact']);

        $this->as($ownerA)->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'tenant', 'enabled' => true])->assertOk();

        $this->assertTrue($this->states($a)['marketing_impact']['enabled']);
        $this->assertFalse($this->states($b)['marketing_impact']['enabled']);
    }

    public function test_the_operator_kill_switch_beats_everything(): void
    {
        [$tenant, $owner] = $this->workspace(['paid_media', 'marketing_impact']);
        $this->as($owner)->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'tenant', 'enabled' => true])->assertOk();
        config(['capabilities.disabled' => ['marketing_impact', 'smart_links']]);

        $s = $this->states($tenant);
        $this->assertFalse($s['marketing_impact']['enabled']);
        $this->assertSame('platform_disabled', $s['marketing_impact']['reason']);
        $this->assertFalse($s['smart_links']['enabled']);
    }

    public function test_the_route_middleware_refuses_an_off_module_with_the_reason(): void
    {
        Route::middleware(['auth:sanctum', 'tenant', 'module:marketing_impact'])->get('/api/v1/_probe/module', fn () => response()->json(['ok' => true]));
        [, $owner] = $this->workspace(['paid_media', 'marketing_impact']);
        $as = $this->as($owner);

        $as->getJson('/api/v1/_probe/module')->assertForbidden()->assertJsonPath('errors.reason', 'not_activated');

        $as->putJson('/api/v1/settings/capabilities/marketing_impact', ['scope' => 'tenant', 'enabled' => true])->assertOk();
        $as->getJson('/api/v1/_probe/module')->assertOk();
    }
}
