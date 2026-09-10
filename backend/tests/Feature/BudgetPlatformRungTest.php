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
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-GOVERNANCE-001 — the PLATFORM rung, which the customer could see and the operator could not.
 *
 * `budgetPacingByProvider()` has existed since the daily digest needed it, and the generated report
 * and the client's live link have both been rendering it. The product itself had a project total, an
 * account table and a campaign table, and nothing in between — so «which platform is overspending»
 * was a question a client could answer from their report while the person responsible for the
 * account could not.
 *
 * Nothing here computes budgets a second time: the endpoint is the same aggregator call the digest
 * makes, through the same `scoped()` filter every other metrics route uses.
 */
final class BudgetPlatformRungTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@b.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $tenant);
        $this->operator->assignRole($role);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'name' => 'Split',
            'objective' => 'sales', 'status' => 'active', 'total_budget' => 10_000, 'budget_currency' => 'SAR',
        ]);

        foreach ([['meta', 3_000], ['snapchat', 1_000]] as [$provider, $amount]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'project_id' => $this->project->id,
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $campaign->id,
                'provider' => $provider,
                'metric_key' => 'spend',
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $amount,
                'project_currency' => 'SAR',
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function platforms(string $extra = ''): array
    {
        return (array) $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/budget-platforms?from="
                .now()->subDays(29)->toDateString().'&to='.now()->toDateString().$extra)
            ->assertOk()
            ->json('data');
    }

    public function test_the_platform_rung_is_reachable_and_splits_the_spend(): void
    {
        $rows = collect($this->platforms())->keyBy('provider');

        $this->assertEqualsWithDelta(3_000, $rows['meta']['spent'], 0.001);
        $this->assertEqualsWithDelta(1_000, $rows['snapchat']['spent'], 0.001);
    }

    /** The same shape the campaign rung returns, so one table reads both rather than two that drift. */
    public function test_it_carries_the_figures_the_hierarchy_is_built_on(): void
    {
        $row = collect($this->platforms())->firstWhere('provider', 'meta');

        foreach (['budget', 'spent', 'remaining', 'consumed_pct', 'pace', 'projected_spend', 'pacing_basis'] as $key) {
            $this->assertArrayHasKey($key, $row, "the platform rung is missing «{$key}», which its table reads");
        }
    }

    /** It narrows like every other metrics route — the platform chip is not decoration here either. */
    public function test_the_platform_filter_narrows_it(): void
    {
        $rows = $this->platforms('&provider=meta');

        $this->assertCount(1, $rows);
        $this->assertSame('meta', $rows[0]['provider']);
    }

    /** And it is behind the same capability as the budget rungs beside it. */
    public function test_it_is_refused_without_the_budget_capability(): void
    {
        $role = Role::create(['tenant_id' => $this->project->tenant_id, 'name' => 'NB', 'slug' => 'nb-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'campaigns.view');

        $reader = User::create(['name' => 'R', 'email' => 'r-'.uniqid().'@b.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($reader, $this->project->tenant);
        $reader->assignRole($role);

        $this->actingAs($reader, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/budget-platforms")
            ->assertForbidden();
    }
}
