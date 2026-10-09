<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\DemoAnalyticsSeeder;
use Database\Seeders\DemoIntegrationsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The integration chain belongs to the analytics demo's STORE project, whatever else the tenant holds.
 *
 * `DemoIntegrationsSeeder` chose «the project holding the most campaigns» — true of the store project
 * for as long as it was the biggest. `DemoAnalyticsSeeder::seedScaleProject` then wrote 241 campaigns
 * into a sibling project, the rule followed the count, and every account, external campaign and ad
 * landed under «Scale — 241 campaigns» while the store project's Ads table read «no data for the
 * period» on all three gate browsers (#631). The choice is by name now; the count is the fallback.
 */
final class DemoIntegrationsSeederTargetTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'cl-'.uniqid(), 'mode' => 'managed']);
    }

    /** The owner's case: a bigger sibling does not take the chain away from the store project. */
    public function test_the_store_project_is_chosen_over_a_sibling_with_more_campaigns(): void
    {
        $store = $this->project(DemoAnalyticsSeeder::STORE_PROJECT, 3);
        $this->project(DemoAnalyticsSeeder::SCALE_PROJECT, 40);

        $this->assertSame((string) $store->id, DemoIntegrationsSeeder::targetProjectId((string) $this->tenant->id));
    }

    /** A tenant with no store project keeps the old rule — the chain goes where the campaigns are. */
    public function test_without_a_store_project_the_biggest_project_is_chosen(): void
    {
        $this->project('Journey', 0);
        $big = $this->project('Big', 5);

        $this->assertSame((string) $big->id, DemoIntegrationsSeeder::targetProjectId((string) $this->tenant->id));
    }

    /** Another tenant's store project is not this tenant's. */
    public function test_the_name_is_matched_inside_the_tenant_only(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        Project::withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'client_workspace_id' => $this->workspace->id,
            'name' => DemoAnalyticsSeeder::STORE_PROJECT, 'status' => 'active',
        ]);
        $mine = $this->project('Mine', 2);

        $this->assertSame((string) $mine->id, DemoIntegrationsSeeder::targetProjectId((string) $this->tenant->id));
    }

    public function test_an_empty_tenant_yields_nothing(): void
    {
        $this->assertNull(DemoIntegrationsSeeder::targetProjectId((string) $this->tenant->id));
    }

    private function project(string $name, int $campaigns): Project
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);

        for ($i = 0; $i < $campaigns; $i++) {
            UnifiedCampaign::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
                'client_workspace_id' => $this->workspace->id,
                'name' => "{$name} {$i}", 'status' => 'active', 'objective' => 'sales',
            ]);
        }

        return $project;
    }
}
