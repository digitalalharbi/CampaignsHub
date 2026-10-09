<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Budget pacing — «no delivery» is a measured zero ONLY when the window was measured at all.
 *
 * `budgetPacing()` records, with its reason, that a budgeted campaign with no metric row in the
 * window spent nothing: nothing was delivered, so the figure is zero in the budget's currency, and
 * leaving it «unknown» would pull the whole budget out of the portfolio's remaining. That holds when
 * the connector was reporting. It does not hold when NOTHING in the project was measured in the
 * window — a stopped sync, a store not yet connected, an estate with only seeded rows — where the
 * same zero is an absence drawn as a figure, and the dashboard's first screen read «0.00× — on
 * budget» for a client whose money nobody had looked at. Owner directive 2026-10-09 §19:
 * «confirmed zero» and «not reported» must not render the same.
 *
 * The condition is the PROJECT's window: a project with any measured row is a project whose
 * connector was answering, and a budgeted campaign of it with no row then genuinely delivered
 * nothing. A project with no measured row has no zero to state.
 */
final class BudgetPacingNothingMeasuredTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
        $this->client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nakheel', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name): Project
    {
        return Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => $name, 'status' => 'active']);
    }

    private function campaign(Project $project, float $budget, ?float $spend): UnifiedCampaign
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'C-'.uniqid(),
            'objective' => 'sales', 'status' => 'active', 'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
        if ($spend !== null) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $campaign->id, 'provider' => 'meta', 'metric_key' => 'spend',
                'metric_date' => now()->subDays(2)->toDateString(), 'value' => $spend, 'project_currency' => 'SAR',
            ]);
        }

        return $campaign;
    }

    /** @return list<array<string, mixed>> */
    private function pacing(Project $project): array
    {
        return app(MetricsAggregator::class)->forProjects([(string) $project->id])
            ->budgetPacing(Carbon::today()->subDays(29), Carbon::today(), Carbon::today());
    }

    /** The decision this file refines, kept: a measured project's silent campaign spent zero. */
    public function test_a_budgeted_campaign_with_no_row_in_a_measured_project_is_a_measured_zero(): void
    {
        $project = $this->project('Measured');
        $this->campaign($project, 10_000, 1_200.0);
        $silent = $this->campaign($project, 5_000, null);

        $row = collect($this->pacing($project))->firstWhere('campaign_id', $silent->id);

        $this->assertSame(0.0, (float) $row['spent']);
        $this->assertTrue($row['measured']);
        $this->assertSame('comparable', $row['pacing_basis']);
    }

    /** The refinement: nothing measured in the whole project's window is not a zero. */
    public function test_a_budgeted_campaign_in_an_unmeasured_project_has_no_figure_and_says_why(): void
    {
        $project = $this->project('Unmeasured');
        $silent = $this->campaign($project, 5_000, null);

        $row = collect($this->pacing($project))->firstWhere('campaign_id', $silent->id);

        $this->assertNull($row['spent'], 'an unmeasured window was drawn as a spent figure');
        $this->assertNull($row['pace'], 'an unmeasured window was paced');
        $this->assertFalse($row['measured']);
        $this->assertSame('nothing_measured_in_window', $row['spend_state']);
        $this->assertSame('nothing_measured_in_window', $row['pacing_basis']);
    }

    /** The client row names the unmeasured campaigns rather than filing them under «excluded». */
    public function test_the_client_row_counts_what_was_not_measured_by_name(): void
    {
        $project = $this->project('Unmeasured');
        $this->campaign($project, 5_000, null);
        $this->campaign($project, 7_000, null);

        $rows = (array) $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/agency/client-budgets?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString())
            ->assertOk()->json('data');
        $client = collect($rows)->firstWhere('client_id', (string) $this->client->id);

        $this->assertSame(2, $client['unmeasured'], 'the unmeasured campaigns were not counted by name');
        $this->assertSame(0, $client['excluded'], 'an unmeasured campaign was filed as «excluded»');
        $this->assertNull($client['spent']);
        $this->assertNull($client['pace'], 'a client nobody measured was paced');
    }
}
