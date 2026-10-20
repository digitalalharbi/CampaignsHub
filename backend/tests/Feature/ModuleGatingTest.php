<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\ShortLinks\Models\ShortLink;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CAP-REGISTRY-001 — the registry is not decoration: the routes it names refuse when their module is off, a project's
 * switch is honoured beneath an agency's, the public link keeps working, and the core product does not notice.
 */
final class ModuleGatingTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'gate-'.uniqid(), 'status' => 'active', 'enabled_modules' => ['paid_media', 'advanced_tracking', 'attribution_intelligence']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@g.test', 'password' => 'secret1234']);
        $this->owner->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $this->owner, tenant: $this->tenant, portal: Portal::App, role: 'owner'));
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    private function switch(string $module, bool $on, string $scope = 'tenant', ?string $id = null): void
    {
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $this->tenant->id, 'scope_type' => $scope, 'scope_key' => $id ?? $this->tenant->id, 'module' => $module],
            ['scope_id' => $id, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $this->tenant->id);
    }

    private function as()
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    public function test_smart_link_management_is_on_by_default_and_refuses_when_its_module_is_switched_off_but_existing_links_keep_working(): void
    {
        $this->as()->getJson('/api/v1/short-links')->assertOk();
        $created = $this->as()->postJson('/api/v1/short-links', ['kind' => 'whatsapp', 'value' => '966500000009'])->assertCreated()->json('data');
        $slug = ShortLink::withoutGlobalScopes()->findOrFail($created['id'])->slug;

        $this->switch('smart_links', false);

        foreach ([
            ['getJson', '/api/v1/short-links'],
            ['postJson', '/api/v1/short-links', ['kind' => 'whatsapp', 'value' => '966500000010']],
            ['patchJson', "/api/v1/short-links/{$created['id']}", ['message' => 'x']],
            ['postJson', "/api/v1/short-links/{$created['id']}/disable"],
            ['deleteJson', "/api/v1/short-links/{$created['id']}"],
        ] as $call) {
            $this->as()->{$call[0]}($call[1], $call[2] ?? [])->assertForbidden()->assertJsonPath('errors.module', 'smart_links')->assertJsonPath('errors.reason', 'tenant_off');
        }

        // A link already printed on a leaflet is not broken by the agency switching the management module off.
        $this->get('/l/'.$slug)->assertStatus(302)->assertRedirect('https://wa.me/966500000009');

        $this->switch('smart_links', true);
        $this->as()->getJson('/api/v1/short-links')->assertOk();
    }

    public function test_the_advanced_routes_are_refused_with_the_module_named_until_it_is_activated_and_by_a_project_beneath_an_agency(): void
    {
        $base = "/api/v1/projects/{$this->project->id}";
        $routes = [
            ['getJson', "{$base}/tracking/summary", 'advanced_tracking'],
            ['getJson', "{$base}/attribution-settings", 'attribution_intelligence'],
            ['putJson', "{$base}/attribution-settings", 'attribution_intelligence', ['models' => ['linear']]],
        ];

        // Entitled but never activated: refused, and the reason says so.
        foreach ($routes as $r) {
            [$method, $url, $module] = $r;
            $this->as()->{$method}($url, $r[3] ?? [])->assertForbidden()->assertJsonPath('errors.module', $module)->assertJsonPath('errors.reason', 'not_activated');
        }

        $this->switch('advanced_tracking', true);
        $this->switch('attribution_intelligence', true);
        foreach ($routes as $r) {
            $this->as()->{$r[0]}($r[1], $r[3] ?? [])->assertOk();
        }

        // The project turns tracking off beneath the agency's yes.
        $this->switch('advanced_tracking', false, 'project', (string) $this->project->id);
        $this->as()->getJson("{$base}/tracking/summary")->assertForbidden()->assertJsonPath('errors.reason', 'project_off');
        $this->as()->getJson("{$base}/attribution-settings")->assertOk();
    }

    public function test_a_workspace_that_never_had_an_advanced_module_cannot_reach_its_routes_whatever_a_switch_says(): void
    {
        $this->tenant->forceFill(['enabled_modules' => ['paid_media']])->save();
        $this->switch('advanced_tracking', true);
        $this->switch('attribution_intelligence', true);
        $base = "/api/v1/projects/{$this->project->id}";

        $this->as()->getJson("{$base}/tracking/summary")->assertForbidden()->assertJsonPath('errors.reason', 'not_entitled');
        $this->as()->putJson("{$base}/attribution-settings", ['models' => ['linear']])->assertForbidden()->assertJsonPath('errors.reason', 'not_entitled');
    }

    public function test_the_core_product_does_not_notice_the_advanced_modules_being_off(): void
    {
        $this->tenant->forceFill(['enabled_modules' => ['paid_media']])->save();
        $base = "/api/v1/projects/{$this->project->id}";

        $this->as()->getJson($base)->assertOk();
        $this->as()->getJson("{$base}/metrics/attribution")->assertOk()->assertJsonMissingPath('data.model_comparison.credit_models');
        $this->as()->getJson("{$base}/widgets/dashboard")->assertOk();
        $this->as()->getJson('/api/v1/short-links')->assertOk();
    }
}
