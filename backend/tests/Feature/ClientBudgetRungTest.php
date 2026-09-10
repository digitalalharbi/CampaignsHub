<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ClientBudgetRollup;
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
 * BUDGET-GOVERNANCE-001 — the CLIENT rung: «which client is overspending».
 *
 * The agency dashboard carried counts of clients, projects and campaigns and no money at all, so
 * that question could not be asked anywhere in the product. The four lower rungs are grains of one
 * query; this one rolls up across a client's projects.
 *
 * The arithmetic mirrors `portfolioBudget()` on the frontend deliberately, and the two are held to
 * the same properties here so they cannot drift into disagreeing about one client on two screens.
 */
final class ClientBudgetRungTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function campaign(ClientWorkspace $client, float $budget, ?float $spend, string $currency = 'SAR'): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P-'.uniqid(), 'status' => 'active',
        ]);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => 'C-'.uniqid(),
            'objective' => 'sales', 'status' => 'active', 'total_budget' => $budget, 'budget_currency' => $currency,
        ]);

        if ($spend !== null) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $campaign->id,
                'provider' => 'meta',
                'metric_key' => 'spend',
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $spend,
                'project_currency' => $currency,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function budgets(): array
    {
        return (array) $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/v1/agency/client-budgets?from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->json('data');
    }

    public function test_each_client_reports_its_own_committed_budget(): void
    {
        $big = $this->client('Big Spender');
        $this->campaign($big, 40_000, 10_000);
        $this->campaign($big, 20_000, null);

        $small = $this->client('Small');
        $this->campaign($small, 5_000, 1_000);

        $rows = collect($this->budgets())->keyBy('client_name');

        // Committed money counts whether or not it has started moving.
        $this->assertEqualsWithDelta(60_000, $rows['Big Spender']['budget'], 0.01);
        $this->assertEqualsWithDelta(10_000, $rows['Big Spender']['spent'], 0.01);
        $this->assertEqualsWithDelta(50_000, $rows['Big Spender']['remaining'], 0.01);
        $this->assertEqualsWithDelta(5_000, $rows['Small']['budget'], 0.01);
    }

    /** Largest committed budget first — «where is the money» precedes every other question. */
    public function test_the_biggest_client_leads(): void
    {
        $this->campaign($this->client('Small'), 5_000, 1_000);
        $this->campaign($this->client('Big Spender'), 40_000, 10_000);

        $this->assertSame('Big Spender', $this->budgets()[0]['client_name']);
    }

    /**
     * A client with no projects is REPORTED with nothing, not dropped.
     *
     * An agency reading this list is checking every client, and one that silently vanishes is
     * indistinguishable from one that is fine.
     */
    public function test_a_client_with_nothing_is_still_listed(): void
    {
        $this->client('Not started');

        $row = collect($this->budgets())->firstWhere('client_name', 'Not started');

        $this->assertNotNull($row);
        $this->assertNull($row['budget']);
        $this->assertSame(0, $row['projects']);
    }

    /** The rollup recomputes the ratio from totals — the average of two paces is not a client's pace. */
    public function test_the_pace_is_recomputed_from_totals_never_averaged(): void
    {
        $rollup = app(ClientBudgetRollup::class);

        $out = $rollup->of([
            ['pacing_basis' => 'comparable', 'budget' => 100.0, 'spent' => 90.0, 'projected_spend' => 300.0, 'budget_currency' => 'SAR'],
            ['pacing_basis' => 'comparable', 'budget' => 10_000.0, 'spent' => 3_000.0, 'projected_spend' => 9_000.0, 'budget_currency' => 'SAR'],
        ]);

        // Averaging 3.0 and 0.9 would say 1.95 and call the client over; it ends at 9,300 of 10,100.
        $this->assertEqualsWithDelta(9_300 / 10_100, $out['pace'], 0.0001);
        $this->assertTrue($out['pace'] < 1.0);
    }

    /** What cannot be compared is excluded and COUNTED, never dropped in silence. */
    public function test_incomparable_rows_are_excluded_and_counted(): void
    {
        $out = app(ClientBudgetRollup::class)->of([
            ['pacing_basis' => 'comparable', 'budget' => 1_000.0, 'spent' => 400.0, 'projected_spend' => 800.0, 'budget_currency' => 'SAR'],
            ['pacing_basis' => 'currency_mismatch', 'budget' => 500.0, 'spent' => 200.0, 'projected_spend' => 400.0, 'budget_currency' => 'USD'],
        ]);

        $this->assertEqualsWithDelta(1_000, $out['budget'], 0.01);
        $this->assertSame(1, $out['excluded']);
    }

    /** Two currencies among the comparable rows refuses the total rather than adding them. */
    public function test_two_currencies_refuse_the_total(): void
    {
        $out = app(ClientBudgetRollup::class)->of([
            ['pacing_basis' => 'comparable', 'budget' => 1_000.0, 'spent' => 400.0, 'projected_spend' => 800.0, 'budget_currency' => 'SAR'],
            ['pacing_basis' => 'comparable', 'budget' => 500.0, 'spent' => 200.0, 'projected_spend' => 400.0, 'budget_currency' => 'USD'],
        ]);

        $this->assertNull($out['budget']);
        $this->assertSame(2, $out['currencies']);
    }

    /** It answers a money question about every client the reader can reach — both permissions, or none. */
    public function test_it_is_refused_without_the_budget_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid()]);
        $role->givePermissionTo('clients.view', 'projects.view');

        $reader = User::create(['name' => 'R', 'email' => 'r-'.uniqid().'@a.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($reader, $this->tenant);
        $reader->assignRole($role);

        $this->actingAs($reader, 'sanctum')->getJson('/api/v1/agency/client-budgets')->assertForbidden();
    }
}
