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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-GOVERNANCE-001 — the PROJECT rung, the one missing from the middle of the hierarchy.
 *
 * The owner's hierarchy is Client → Project → Platform → Account → Campaign. The client rung reported
 * a project COUNT and nothing else, so a client pacing at 1.4× named no project responsible for it:
 * the operator saw that a client was overspending and had to leave the screen, guess a project, and
 * open it, to learn which one.
 *
 * The rung is the same roll-up applied at a finer grain — `ClientBudgetRollup` over the campaigns of
 * ONE project — so the two rungs cannot disagree about the same money, and a client's total is the
 * sum of exactly the projects printed beneath it.
 *
 * And the cost is asserted, because the rung is an invitation to fetch per project. The pacing query
 * ran once PER CLIENT while the comment above it claimed the opposite; an agency with forty clients
 * paid forty times over for a screen that answers one question.
 */
final class ProjectBudgetRungTest extends TestCase
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

    private function project(ClientWorkspace $client, string $name): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => $name, 'status' => 'active',
        ]);
    }

    private function campaign(Project $project, float $budget, ?float $spend, string $currency = 'SAR'): void
    {
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

    public function test_a_client_names_the_projects_its_money_is_in(): void
    {
        $client = $this->client('Acme');
        $this->campaign($this->project($client, 'Riyadh launch'), 40_000, 30_000);
        $this->campaign($this->project($client, 'Jeddah retainer'), 10_000, 2_000);

        $row = collect($this->budgets())->firstWhere('client_name', 'Acme');

        $projects = collect($row['projects_breakdown'] ?? [])->keyBy('project_name');

        $this->assertCount(2, $projects, 'every project holding the money is named');
        $this->assertEqualsWithDelta(40_000, $projects['Riyadh launch']['budget'], 0.01);
        $this->assertEqualsWithDelta(30_000, $projects['Riyadh launch']['spent'], 0.01);
        $this->assertEqualsWithDelta(2_000, $projects['Jeddah retainer']['spent'], 0.01);
    }

    /** The rung is a decomposition, not a second opinion: the parts add up to the total above them. */
    public function test_the_projects_add_up_to_the_client_total(): void
    {
        $client = $this->client('Acme');
        $this->campaign($this->project($client, 'One'), 40_000, 30_000);
        $this->campaign($this->project($client, 'Two'), 10_000, 2_000);

        $row = collect($this->budgets())->firstWhere('client_name', 'Acme');

        $this->assertEqualsWithDelta(
            (float) $row['budget'],
            collect($row['projects_breakdown'])->sum('budget'),
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) $row['spent'],
            collect($row['projects_breakdown'])->sum('spent'),
            0.01,
        );
    }

    /**
     * The cost does not grow with the number of clients.
     *
     * The pacing query ran once per client. This asserts the shape of the fix rather than a magic
     * number: ten clients must not cost materially more than two.
     */
    public function test_the_query_count_does_not_grow_with_the_client_count(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->campaign($this->project($this->client('C'.$i), 'P'), 1_000, 100);
        }

        DB::enableQueryLog();
        $this->budgets();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();

        /* The request resolves its own tenant and leaves the context cleared behind it. */
        app(TenantContext::class)->setTenantId($this->tenant->id);

        for ($i = 2; $i < 10; $i++) {
            $this->campaign($this->project($this->client('C'.$i), 'P'), 1_000, 100);
        }

        /*
         * Flushed HERE, not after the first measurement: logging stays on across `flushQueryLog()`,
         * so the eight clients created above would otherwise be counted as the endpoint's own cost
         * and the assertion would fail on the fixtures rather than on the code under test.
         */
        DB::flushQueryLog();
        $this->budgets();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $small + 2,
            $large,
            'eight more clients cost '.($large - $small).' more queries — the pacing query is still per client',
        );
    }
}
