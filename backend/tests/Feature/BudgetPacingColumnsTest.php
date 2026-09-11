<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * BUDGET-GOVERNANCE-001 — the three figures the owner's spreadsheet has and the product did not.
 *
 * The budget rungs reported Budget, Spent, Remaining, Consumed, Pace and Projected. Three of the
 * named columns were missing, and each answers a question the others cannot:
 *
 *   - **Expected to date** — what should have been spent by today. `pace` already divides by it, so
 *     the product knew the number and never showed it: a reader told «pace 1.3×» cannot check it, and
 *     cannot see WHICH of the two figures moved.
 *   - **Daily average** — spend ÷ elapsed days. The figure an operator multiplies by the days left
 *     when deciding whether to intervene today or on Thursday.
 *   - **Over / under** — projected end spend minus the plan, in money. «Projected 46,000» against a
 *     40,000 budget makes the reader do the subtraction; the overrun IS the decision.
 *
 * All three refuse exactly where the rest of the row refuses — they are derived from the same two
 * figures, and a column that states a number the row beside it withholds is the money contract
 * broken by a new door.
 */
final class BudgetPacingColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    private function campaign(float $budget, string $currency = 'SAR'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'C-'.uniqid(), 'objective' => 'sales', 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => $currency,
        ]);
    }

    private function spend(UnifiedCampaign $c, float $value, string $date, ?string $currency = 'SAR'): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => $date, 'value' => $value, 'project_currency' => $currency,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        // A ten-day window, five days elapsed: every figure below is checkable by hand.
        return collect(app(MetricsAggregator::class)->forProjects([$this->project->id])->budgetPacing(
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-10'),
            Carbon::parse('2026-08-05'),
        ))->first();
    }

    public function test_it_states_what_should_have_been_spent_by_today(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000, '2026-08-02');

        // Half the window gone, so half the plan: 5,000.
        $this->assertEqualsWithDelta(5_000.0, (float) $this->row()['expected_to_date'], 0.01);
    }

    public function test_it_states_the_daily_average_and_the_overrun(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 3_000, '2026-08-02');

        $row = $this->row();

        // 3,000 over five elapsed days.
        $this->assertEqualsWithDelta(600.0, (float) $row['daily_average'], 0.01);

        // Projected 6,000 against a 10,000 plan: 4,000 under, stated as a signed figure.
        $this->assertEqualsWithDelta(-4_000.0, (float) $row['over_under'], 0.01);
    }

    /** An overrun is positive, so the sign carries the meaning rather than a second column. */
    public function test_an_overrun_is_positive(): void
    {
        $c = $this->campaign(10_000);
        $this->spend($c, 9_000, '2026-08-02');

        // Projected 18,000 against 10,000.
        $this->assertEqualsWithDelta(8_000.0, (float) $this->row()['over_under'], 0.01);
    }

    /** A row the contract refuses to pace withholds these three as well. */
    public function test_they_are_withheld_where_the_row_is_withheld(): void
    {
        $c = $this->campaign(10_000, 'USD');
        $this->spend($c, 3_000, '2026-08-02', 'SAR');

        $row = $this->row();

        $this->assertSame('currency_mismatch', $row['pacing_basis']);
        $this->assertNull($row['expected_to_date']);
        $this->assertNull($row['daily_average']);
        $this->assertNull($row['over_under']);
    }
}
