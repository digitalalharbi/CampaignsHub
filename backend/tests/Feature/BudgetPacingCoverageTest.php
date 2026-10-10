<?php

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\SpendLimitGovernor;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-PACING-COVERAGE-001 — «elapsed» ends where the measurement ends.
 *
 * A thirty-day window, today on its twentieth day, 1,000 a day spent on days 1–10, and a Meta sync
 * that stopped on day 10. Paced to TODAY the figures read 10,000 over twenty days: 500/day,
 * 15,000 projected on a 30,000 budget, «under budget». Paced to the day the data actually runs
 * through they read 1,000/day, 30,000 projected — on budget — and the row says through when.
 */
final class BudgetPacingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'C-1', 'objective' => 'sales', 'status' => 'active', 'total_budget' => 30_000, 'budget_currency' => 'SAR',
        ]);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token-meta');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Ad account', 'status' => 'active']);
        $this->accountId = (string) $account->id;
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $client->id,
            'unified_campaign_id' => $this->campaign->id, 'external_account_id' => $this->accountId, 'provider' => 'meta',
            'external_id' => 'ext-1', 'name' => 'Meta campaign', 'status' => 'active', 'starts_at' => '2026-07-01', 'ends_at' => null,
        ]);
        for ($day = 1; $day <= 10; $day++) {
            DailyMetric::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $this->accountId, 'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->id, 'provider' => 'meta', 'metric_key' => 'spend',
                'metric_date' => sprintf('2026-08-%02d', $day), 'value' => 1_000, 'project_currency' => 'SAR',
            ]);
        }
    }

    private function stoppedOnDayTen(): void
    {
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->accountId,
            'provider' => 'meta', 'status' => 'success', 'window_start' => '2026-07-01', 'window_end' => '2026-08-10',
            'finished_at' => Carbon::parse('2026-08-10 23:00:00'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return collect(app(MetricsAggregator::class)->forProjects([$this->project->id])->budgetPacing(
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-30'), Carbon::parse('2026-08-20'),
        ))->first();
    }

    public function test_without_a_checkpoint_the_pace_runs_to_today(): void
    {
        $row = $this->row();

        $this->assertNull($row['paced_through']);
        $this->assertSame(500.0, (float) $row['daily_average']);
        $this->assertSame(15_000.0, (float) $row['projected_spend']);
    }

    public function test_a_platform_that_stopped_short_paces_through_the_day_it_reported(): void
    {
        $this->stoppedOnDayTen();

        $row = $this->row();

        $this->assertSame('2026-08-10', $row['paced_through']);
        $this->assertSame('comparable', $row['pacing_basis']);
        $this->assertSame(1_000.0, (float) $row['daily_average']);
        $this->assertSame(30_000.0, (float) $row['projected_spend']);
        // Expected-to-date is the budget's share of the ten covered days, not of twenty.
        $this->assertSame(10_000.0, (float) $row['expected_to_date']);
        $this->assertSame(1.0, (float) $row['pace']);
    }

    public function test_the_platform_rows_pace_the_same_way(): void
    {
        $this->stoppedOnDayTen();

        $row = collect(app(MetricsAggregator::class)->forProjects([$this->project->id])->budgetPacingByProvider(
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-30'), Carbon::parse('2026-08-20'),
        ))->first();

        $this->assertSame('2026-08-10', $row['paced_through']);
    }

    public function test_a_spend_limit_is_read_through_the_same_day(): void
    {
        $this->stoppedOnDayTen();
        $limit = SpendLimit::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'scope' => 'project', 'scope_id' => null,
            'amount' => 30_000, 'currency' => 'SAR', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-30',
        ]);

        $reading = app(SpendLimitGovernor::class)->read($limit, Carbon::parse('2026-08-20'));

        $this->assertSame('2026-08-10', $reading['paced_through']);
        $this->assertSame(10, $reading['elapsed_days']);
        $this->assertSame(30_000.0, (float) $reading['projected_period_spend']);
        $this->assertSame(1.0, (float) $reading['pace']);
    }
}
