<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAIGNS-TABLE-COMPARISON-001 — a comparison table sorts the SET, never the page.
 *
 * «Use strong, professional tables: sortable, filterable, searchable … use tables when comparison is
 * the job.» The campaigns table offered five columns — name, objective, status, budget, linked — and
 * no way to order them, so comparing two campaigns on what they cost meant opening both.
 *
 * The trap this is written against is the obvious fix. The list is server-paginated and
 * server-ranked (#362), so sorting the twenty-five rows the browser happens to hold would answer
 * «the most expensive of the twenty-five most relevant» while looking exactly like an answer about
 * the project. Every case below asserts on WHICH campaigns come back, not on their order within a
 * page, because only the first can tell the two apart.
 */
/**
 * OBJECTIVE-RESULT-001 — «sort by results» ranks each campaign by ITS OWN result.
 *
 * The list sorted on `conversions` for every campaign, so a traffic campaign with thousands of
 * landing page views sorted beside campaigns with nothing, as «0 results». An unclassified campaign
 * has no result at all and goes last, whichever way the list is sorted.
 */
final class UnifiedCampaignSortTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'Project 1',
            'status' => 'active',
        ]);

        $this->user = User::create([
            'name' => 'O', 'email' => 'o-'.uniqid().'@agency.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user->assignRole($role);
        $this->grantMembership($this->user, $this->tenant, Portal::App);
    }

    private function campaign(string $name, string $objective, array $figures): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective,
        ]);

        foreach ($figures as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $campaign->id,
                'provider' => 'snapchat',
                'metric_key' => $key,
                'metric_date' => now()->subDay()->toDateString(),
                'value' => $value,
                'project_currency' => 'SAR',
            ]);
        }
    }

    /** @return list<string> */
    private function page(array $params): array
    {
        $query = http_build_query($params + [
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
            'per_page' => 10,
        ]);

        return array_column($this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns?{$query}")
            ->assertOk()
            ->json('data'), 'name');
    }

    public function test_results_are_ranked_within_one_unit_and_an_unset_objective_goes_last(): void
    {
        $this->campaign('Coffee traffic', 'traffic', ['spend' => 1440, 'clicks' => 18464, 'landing_page_views' => 2881, 'conversions' => 0]);
        $this->campaign('Sales', 'sales', ['spend' => 300, 'clicks' => 900, 'conversions' => 12]);
        $this->campaign('Unclassified', 'other', ['spend' => 50, 'clicks' => 99999, 'conversions' => 500]);

        $this->campaign('Coffee traffic 2', 'traffic', ['spend' => 100, 'clicks' => 900, 'landing_page_views' => 50]);
        $this->campaign('Sales 2', 'sales', ['spend' => 100, 'clicks' => 900, 'conversions' => 30]);

        /*
         * Grouped by unit (orders «conversions», then visits «landing_page_views»), ranked inside each
         * group, and the campaign with no result last — 2,881 visits are never ranked against 30 orders.
         */
        $this->assertSame(['Sales 2', 'Sales', 'Coffee traffic', 'Coffee traffic 2', 'Unclassified'], $this->page(['sort' => 'results', 'dir' => 'desc']));
        $this->assertSame(['Sales', 'Sales 2', 'Coffee traffic 2', 'Coffee traffic', 'Unclassified'], $this->page(['sort' => 'results', 'dir' => 'asc']));
    }
}
