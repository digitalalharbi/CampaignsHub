<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Disclaimers\Models\Disclaimer;
use App\Domains\Disclaimers\Services\DisclaimerResolver;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Central disclaimer/methodology: system defaults resolve everywhere, scoped overrides merge in
 * priority order (project → client → organization → system), edits are audited and permission-gated.
 */
final class DisclaimerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $client;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $this->client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $this->client->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_system_default_resolves_when_no_override(): void
    {
        $out = app(DisclaimerResolver::class)->resolve($this->tenant->id);

        $this->assertSame(['system'], $out['sources']);
        $this->assertStringContainsString('منهجية قائمة على الأداء', $out['sections']['short']['ar']);
        $this->assertArrayHasKey('en', $out['sections']['full']);
    }

    public function test_project_override_wins_over_organization_and_system(): void
    {
        Disclaimer::create([
            'tenant_id' => $this->tenant->id, 'scope' => 'organization', 'scope_id' => null,
            'payload' => ['sections' => ['short' => ['ar' => 'نص المؤسسة']]],
        ]);
        Disclaimer::create([
            'tenant_id' => $this->tenant->id, 'scope' => 'project', 'scope_id' => $this->project->id,
            'payload' => ['sections' => ['short' => ['ar' => 'نص المشروع']]],
        ]);

        $out = app(DisclaimerResolver::class)->resolve($this->tenant->id, $this->client->id, $this->project->id);

        $this->assertSame('نص المشروع', $out['sections']['short']['ar']);
        // Untouched sections keep the system default.
        $this->assertArrayHasKey('methodology', $out['sections']);
        $this->assertContains('project', $out['sources']);
    }

    public function test_disabled_section_is_dropped_in_for_report(): void
    {
        Disclaimer::create([
            'tenant_id' => $this->tenant->id, 'scope' => 'organization', 'scope_id' => null,
            'payload' => ['enabled' => ['freshness' => false]],
        ]);

        $bundle = app(DisclaimerResolver::class)->forReport($this->tenant->id, null, null, 'ar', 'sales');

        $this->assertNull($bundle['freshness']);
        $this->assertNotNull($bundle['short']);
        $this->assertNotNull($bundle['objective']); // sales objective note present
    }

    public function test_update_endpoint_versions_and_requires_permission(): void
    {
        Sanctum::actingAs($this->owner);

        $res = $this->putJson('/api/v1/settings/disclaimers', [
            'scope' => 'organization',
            'payload' => ['sections' => ['short' => ['ar' => 'v1']]],
        ]);
        $res->assertOk();
        $this->assertSame(1, Disclaimer::withoutGlobalScopes()->first()->version);

        $this->putJson('/api/v1/settings/disclaimers', [
            'scope' => 'organization',
            'payload' => ['sections' => ['short' => ['ar' => 'v2']]],
        ])->assertOk();
        $this->assertSame(2, Disclaimer::withoutGlobalScopes()->first()->version);

        $this->assertDatabaseHas('audit_logs', ['action' => 'disclaimer.updated']);
    }

    public function test_update_forbidden_without_settings_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);

        $this->putJson('/api/v1/settings/disclaimers', [
            'scope' => 'organization', 'payload' => ['sections' => []],
        ])->assertForbidden();
    }
}
