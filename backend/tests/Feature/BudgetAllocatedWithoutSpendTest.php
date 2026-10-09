<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-GOVERNANCE-001 — money that is COMMITTED but not yet spent is still money.
 *
 * `budgetPacing()` built its row set from `$spentByCampaign->keys()` — the campaigns that had spend
 * in the window. A campaign with a budget allocated and no delivery yet produced no row at all, so:
 *
 *   - it was invisible in the budget view, which is the one screen that exists to answer «what have
 *     we committed»;
 *   - and the portfolio total UNDERSTATED the commitment by exactly its budget, silently.
 *
 * That is the figure a spreadsheet is kept for. An operator planning next week's money needs the
 * 50,000 sitting on a campaign that starts on Sunday to be in the total, not to appear on Monday
 * once the first riyal is spent.
 *
 * The reverse case is kept as it was: spend on a campaign with NO budget is still reported, because
 * money leaving the account is never hidden for want of a plan to compare it against.
 */
final class BudgetAllocatedWithoutSpendTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    private function campaign(string $name, float $budget): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => $name, 'objective' => 'sales',
            'status' => 'active', 'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
    }

    private function spend(UnifiedCampaign $c, float $amount): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => '2026-07-10',
            'value' => $amount,
            'project_currency' => 'SAR',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function pacing(): array
    {
        return app(MetricsAggregator::class)
            ->forProjects([$this->project->id])
            ->budgetPacing(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), Carbon::parse('2026-07-15'));
    }

    public function test_a_budget_with_no_spend_yet_is_still_reported(): void
    {
        $this->campaign('Starts on Sunday', 50_000);

        $rows = $this->pacing();

        $this->assertCount(1, $rows, 'a campaign with a budget and no delivery was left out of the budget view');
        $this->assertSame('Starts on Sunday', $rows[0]['campaign_name']);
        $this->assertSame(50_000.0, $rows[0]['budget']);
    }

    /**
     * Nothing MEASURED is «we cannot tell», not zero — Owner directive 2026-10-09 §19.
     *
     * This case once asserted the opposite («no delivery happened, so nothing was spent») on a project
     * that holds no measured row at all. That conflated two facts: a campaign whose sources were read
     * and reported nothing (a measured zero, still reported as zero below), and a campaign whose
     * project nobody has read in this window (no figure). The second put «0.00 spent» beside every
     * client whose sources had never synced, which is the dishonest reading the directive forbids.
     * The budget is still the budget; the spend, the remainder and the consumption are not known.
     */
    public function test_an_unstarted_campaign_in_an_unread_project_reports_no_spend_and_its_budget(): void
    {
        $this->campaign('Starts on Sunday', 50_000);

        $row = $this->pacing()[0];

        $this->assertSame(50_000.0, $row['budget']);
        $this->assertNull($row['spent']);
        $this->assertNull($row['remaining']);
        $this->assertNull($row['consumed_pct']);
        $this->assertSame('nothing_measured_in_window', $row['pacing_basis']);
        $this->assertFalse($row['measured']);
    }

    /**
     * The measured zero is still a zero: a project whose window WAS read reports an unstarted
     * sibling as spent 0.0 with its whole budget remaining — the reading above exists only where
     * no reading happened.
     */
    public function test_an_unstarted_campaign_beside_a_measured_one_reports_a_measured_zero(): void
    {
        $this->campaign('Starts on Sunday', 50_000);
        $this->spend($this->campaign('Running', 10_000), 2_500);

        $rows = collect($this->pacing())->keyBy('campaign_name');

        $this->assertSame(0.0, $rows['Starts on Sunday']['spent']);
        $this->assertSame(50_000.0, $rows['Starts on Sunday']['remaining']);
        $this->assertSame(0.0, $rows['Starts on Sunday']['consumed_pct']);
        $this->assertSame('comparable', $rows['Starts on Sunday']['pacing_basis']);
        $this->assertTrue($rows['Starts on Sunday']['measured']);
    }

    /** And the portfolio total carries it, which is the whole point. */
    public function test_the_portfolio_total_includes_the_committed_budget(): void
    {
        $running = $this->campaign('Running', 10_000);
        $this->spend($running, 4_000);
        $this->campaign('Starts on Sunday', 50_000);

        $rows = $this->pacing();
        $total = array_sum(array_column($rows, 'budget'));

        $this->assertCount(2, $rows);
        $this->assertSame(60_000.0, $total, 'the committed budget was understated by the campaign that had not started');
    }

    /** Spend without a budget is still reported — money leaving the account is never hidden. */
    public function test_spend_without_a_budget_is_still_reported(): void
    {
        $unplanned = $this->campaign('No budget set', 0);
        $this->spend($unplanned, 900);

        $rows = $this->pacing();

        $this->assertCount(1, $rows);
        $this->assertSame(900.0, $rows[0]['spent']);
        $this->assertSame('no_budget', $rows[0]['pacing_basis']);
    }

    /** A campaign with neither a budget nor spend is not a row — there is nothing to say about it. */
    public function test_a_campaign_with_neither_is_not_listed(): void
    {
        $this->campaign('Draft, nothing planned', 0);

        $this->assertSame([], $this->pacing());
    }
}
