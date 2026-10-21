<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Capabilities\Capabilities;
use App\Domains\Accounts\Capabilities\CapabilitySwitch;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Widgets\Models\WidgetLayout;
use App\Domains\Widgets\Services\WidgetLayouts;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WIDGET-REGISTRY-001 — which widgets a surface shows, decided on the server, narrowing at every step.
 */
final class WidgetRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const SPA = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private Project $project;

    private ClientWorkspace $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        [$this->tenant, $this->owner, $this->client, $this->project] = $this->workspace(['paid_media', 'advanced_tracking', 'attribution_intelligence']);
    }

    /** @param list<string> $modules @return array{0: Tenant, 1: User, 2: ClientWorkspace, 3: Project} */
    private function workspace(array $modules, bool $manager = true): array
    {
        $tenant = Tenant::create(['name' => 'W', 'slug' => 'wid-'.uniqid(), 'status' => 'active', 'enabled_modules' => $modules]);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $manager ? 'Owner' : 'Viewer', 'slug' => $manager ? 'tenant-owner' : 'viewer-'.uniqid()]);
        if ($manager) {
            $role->givePermissionTo(...Permission::pluck('key')->all());
        } else {
            $role->givePermissionTo('analytics.view');
        }
        $user = User::create(['name' => 'U', 'email' => uniqid().'@w.test', 'password' => 'secret1234']);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $user, tenant: $tenant, portal: Portal::App, role: $manager ? 'owner' : 'viewer'));
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();

        return [$tenant, $user, $client, $project];
    }

    private function module(string $module, bool $on, ?Tenant $tenant = null, string $scope = 'tenant', ?string $id = null): void
    {
        $tenant ??= $this->tenant;
        CapabilitySwitch::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'scope_type' => $scope, 'scope_key' => $id ?? $tenant->id, 'module' => $module],
            ['scope_id' => $id, 'enabled' => $on],
        );
        app(Capabilities::class)->bump((string) $tenant->id);
    }

    private function as(?User $user = null)
    {
        return $this->actingAs($user ?? $this->owner, 'sanctum')->withHeaders(self::SPA);
    }

    /** @return array<string,array<string,mixed>> */
    private function widgets(string $surface, ?Project $project = null, ?User $user = null): array
    {
        $project ??= $this->project;
        $rows = $this->as($user)->getJson("/api/v1/projects/{$project->id}/widgets/{$surface}")->assertOk()->json('data.widgets');

        return collect($rows)->keyBy('key')->all();
    }

    public function test_a_widget_behind_a_module_that_is_off_is_listed_as_off_on_the_agency_surfaces_and_absent_on_the_client_one(): void
    {
        $this->assertSame('module_off', $this->widgets('dashboard')['link_opens']['reason']);
        $this->assertFalse($this->widgets('dashboard')['link_opens']['visible']);

        $this->module('advanced_tracking', true);
        $this->module('attribution_intelligence', true);
        $w = $this->widgets('dashboard');
        $this->assertTrue($w['link_opens']['visible']);
        $this->assertNull($w['link_opens']['reason']);
        $this->assertTrue($w['attribution_credit']['visible']);

        // On the client-facing surface the project's layout is a permission: allowed by default, and each link still shows nothing until switched on.
        $report = $this->widgets('report');
        $this->assertTrue($report['link_opens']['visible']);

        $this->module('advanced_tracking', false);
        $this->assertArrayNotHasKey('link_opens', $this->widgets('report'), 'a widget whose module is off is not even listed to a client');
    }

    public function test_core_widgets_are_opt_in_so_a_project_that_chose_nothing_keeps_the_page_it_had(): void
    {
        $w = $this->widgets('dashboard');
        foreach (['report_views', 'commerce_outcomes'] as $core) {
            $this->assertFalse($w[$core]['visible'], "{$core} is hidden until chosen");
            $this->assertNull($w[$core]['reason'], "{$core} is not blocked, only not chosen");
        }
        // Not offered on the client surface at all: report link opens are the agency's own diagnostic.
        $this->assertArrayNotHasKey('report_views', $this->widgets('report'));
        $this->assertArrayNotHasKey('commerce_outcomes', $this->widgets('report'));

        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/dashboard", ['items' => [['key' => 'report_views', 'visible' => true, 'order' => 1, 'size' => 'half']]])->assertOk();
        $this->assertTrue($this->widgets('dashboard')['report_views']['visible']);

        // Commerce outcomes sit behind a module that is on by default; switching that module off removes it whatever was chosen.
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/dashboard", ['items' => [['key' => 'commerce_outcomes', 'visible' => true, 'order' => 1, 'size' => 'half']]])->assertOk();
        $this->assertTrue($this->widgets('dashboard')['commerce_outcomes']['visible']);
        $this->module('commerce_intelligence', false);
        $this->assertSame('module_off', $this->widgets('dashboard')['commerce_outcomes']['reason']);
    }

    public function test_an_owner_can_hide_reorder_and_resize_and_the_choice_is_saved_audited_and_per_project(): void
    {
        $this->module('advanced_tracking', true);
        $this->module('attribution_intelligence', true);

        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/dashboard", ['items' => [
            ['key' => 'attribution_credit', 'visible' => true, 'order' => 1, 'size' => 'full'],
            ['key' => 'link_opens', 'visible' => false, 'order' => 2, 'size' => 'third'],
        ]])->assertOk();

        $order = collect($this->as()->getJson("/api/v1/projects/{$this->project->id}/widgets/dashboard")->json('data.widgets'))->keyBy('key');
        $this->assertSame(['attribution_credit', 'link_opens'], array_values(array_intersect($order->keys()->all(), ['attribution_credit', 'link_opens'])), 'ordered by the saved order');
        $this->assertSame('attribution_credit', $order->keys()->first(), 'the widget saved first comes first');
        $this->assertTrue($order['attribution_credit']['visible']);
        $this->assertSame('full', $order['attribution_credit']['size']);
        $this->assertFalse($order['link_opens']['visible']);
        $this->assertSame('hidden_by_project', $order['link_opens']['reason']);

        $log = AuditLog::withoutGlobalScopes()->where('action', 'widgets.layout_changed')->where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('dashboard', $log->after['surface']);

        // Another project of the same workspace has its own layout.
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $second = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P2', 'status' => 'active']);
        app(TenantContext::class)->forget();
        $this->assertTrue($this->widgets('dashboard', $second)['link_opens']['visible'], 'the other project still has the defaults');
    }

    public function test_a_module_that_is_off_cannot_be_turned_on_by_a_layout(): void
    {
        $this->module('advanced_tracking', false);
        $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/analytics", ['items' => [['key' => 'link_opens', 'visible' => true, 'order' => 1, 'size' => 'full']]])->assertOk();

        $w = $this->widgets('analytics')['link_opens'];
        $this->assertFalse($w['visible']);
        $this->assertSame('module_off', $w['reason']);
    }

    public function test_a_shared_link_shows_nothing_until_told_to_and_never_what_the_project_forbids(): void
    {
        $this->module('advanced_tracking', true);
        $this->module('attribution_intelligence', true);
        $layouts = app(WidgetLayouts::class);
        // The project allows link activity on client links and forbids the attribution widget there.
        $layouts->save((string) $this->tenant->id, 'project', (string) $this->project->id, 'report', [
            ['key' => 'link_opens', 'visible' => true, 'order' => 1, 'size' => 'full'],
            ['key' => 'attribution_credit', 'visible' => false, 'order' => 2, 'size' => 'full'],
        ], null);
        $untouched = (string) Str::uuid();
        $enabled = (string) Str::uuid();
        $layouts->save((string) $this->tenant->id, 'share_link', $enabled, 'report', [
            ['key' => 'link_opens', 'visible' => true, 'order' => 5, 'size' => 'half'],
            ['key' => 'attribution_credit', 'visible' => true, 'order' => 6, 'size' => 'half'],
        ], null);

        $tenant = Tenant::query()->findOrFail($this->tenant->id);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $project = Project::query()->findOrFail($this->project->id);
        $forUntouched = collect($layouts->resolve($tenant, $project, 'report', $untouched))->keyBy('key');
        $forEnabled = collect($layouts->resolve($tenant, $project, 'report', $enabled))->keyBy('key');
        app(TenantContext::class)->forget();

        $this->assertFalse($forUntouched['link_opens']['visible'], 'a link that was never told to show it shows nothing, though the project allows it');
        $this->assertSame('hidden_by_link', $forUntouched['link_opens']['reason']);

        $this->assertTrue($forEnabled['link_opens']['visible']);
        $this->assertSame(5, $forEnabled['link_opens']['order'], 'the link\'s own order and width win');
        $this->assertFalse($forEnabled['attribution_credit']['visible'], 'the link cannot show what the project forbids');
        $this->assertSame('hidden_by_project', $forEnabled['attribution_credit']['reason']);
    }

    public function test_unknown_widgets_duplicates_and_sizes_are_refused_and_nothing_is_stored(): void
    {
        foreach ([
            [['key' => 'teleporter', 'visible' => true]],
            [['key' => 'link_opens'], ['key' => 'link_opens']],
            [['key' => 'link_opens', 'size' => 'gigantic']],
        ] as $bad) {
            $this->as()->putJson("/api/v1/projects/{$this->project->id}/widgets/dashboard", ['items' => $bad])->assertStatus(422);
        }
        $this->as()->getJson("/api/v1/projects/{$this->project->id}/widgets/nonsense")->assertNotFound();
        $this->assertSame(0, WidgetLayout::withoutGlobalScopes()->count());
    }

    public function test_changing_a_layout_needs_the_manage_permission_and_another_workspaces_project_is_not_reachable(): void
    {
        [, $viewer, , $viewerProject] = $this->workspace(['paid_media', 'advanced_tracking'], manager: false);
        // A member of the workspace who was not given this project can neither read nor change its layout.
        $this->as($viewer)->getJson("/api/v1/projects/{$viewerProject->id}/widgets/dashboard")->assertForbidden();
        $this->as($viewer)->putJson("/api/v1/projects/{$viewerProject->id}/widgets/dashboard", ['items' => [['key' => 'link_opens', 'visible' => false]]])->assertForbidden();

        [$tenantB, $ownerB, , $projectB] = $this->workspace(['paid_media']);
        $this->as($ownerB)->putJson("/api/v1/projects/{$projectB->id}/widgets/dashboard", ['items' => [['key' => 'link_opens', 'visible' => false]]])->assertOk();
        $this->as($this->owner)->getJson("/api/v1/projects/{$projectB->id}/widgets/dashboard")->assertNotFound();
        $this->as($this->owner)->putJson("/api/v1/projects/{$projectB->id}/widgets/dashboard", ['items' => []])->assertNotFound();

        // And the first workspace's layout was not touched by the second's.
        $this->assertSame(0, WidgetLayout::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, WidgetLayout::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }
}
