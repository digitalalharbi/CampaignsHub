<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Branding\Models\BrandingSetting;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BRANDING-TARGET-PICKER-001 — a project / report / portal / email brand must name a record this tenant owns and the caller can
 * reach. Until now only `client` was checked: any uuid, another tenant's included, was stored as the brand's target.
 */
final class BrandingTargetScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $client;

    private Project $project;

    private Report $report;

    private string $foreignProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Store', 'status' => 'active']);
        $report = new Report;
        $report->forceFill(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'R', 'type' => 'weekly', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed', 'period_start' => '2026-10-05', 'period_end' => '2026-10-11', 'currency' => 'USD', 'config' => []])->save();
        $this->report = $report;

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $foreignClient = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'slug' => 'foreign', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->foreignProject = (string) Project::create(['tenant_id' => $other->id, 'client_workspace_id' => $foreignClient->id, 'name' => 'Theirs', 'status' => 'active'])->id;
        app(TenantContext::class)->forget();
    }

    private function save(string $scope, ?string $scopeId)
    {
        return $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/branding/settings', ['scope' => $scope, 'scope_id' => $scopeId, 'colors' => ['primary' => '#112233']]);
    }

    public function test_a_real_target_the_caller_can_reach_is_accepted_for_every_scoped_layer(): void
    {
        $this->save('client', $this->client->id)->assertOk();
        $this->save('portal', $this->client->id)->assertOk();
        $this->save('email', $this->client->id)->assertOk();
        $this->save('project', $this->project->id)->assertOk();
        $this->save('report', $this->report->id)->assertOk();
    }

    public function test_a_uuid_that_names_nothing_here_is_refused_not_stored(): void
    {
        foreach (['client', 'portal', 'email', 'project', 'report'] as $scope) {
            $this->save($scope, (string) Str::uuid())->assertNotFound();
        }
        $this->assertSame(0, BrandingSetting::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_another_tenants_project_is_refused_exactly_like_one_that_does_not_exist(): void
    {
        $this->save('project', $this->foreignProject)->assertNotFound();
        $this->assertSame(0, BrandingSetting::withoutGlobalScopes()->where('scope_id', $this->foreignProject)->count());
    }

    public function test_the_tenant_wide_layers_still_take_no_target(): void
    {
        $this->save('tenant', null)->assertOk();
        $this->save('project', null)->assertOk();
    }
}
