<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ALERTS-TRUTH-001 — «Budget at risk» divided two numbers that were not comparable.
 *
 * `budgetRisks()` read `(float) $totals['spend']` and compared it to `total_budget`. Two failures
 * sat in that one line, and the money contract had already answered both everywhere else:
 *
 *   1. A spend the contract WITHHELD is null, and `(float) null` is 0.0 — so a campaign whose
 *      platform reported in a currency with no dated rate could burn its whole budget and the alert
 *      stayed silent. A missed warning reports nothing, which is why it survives: nobody sees an
 *      alert that was never raised.
 *
 *   2. The spend is in the project's currency and `total_budget` is in the campaign's. Dividing them
 *      is dividing riyals by dollars: a 1,000 USD budget against a 3,750 SAR spend reads as 375% and
 *      raises an alarm about money that was never overspent.
 *
 * `MetricsAggregator::budgetPacing()` already states `consumed_pct` and refuses it — with a named
 * `pacing_basis` — in exactly these cases. The alert reads that verdict now instead of dividing.
 */
final class BudgetRiskAlertTruthTest extends TestCase
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
            'name' => 'Camp '.uniqid(), 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => $currency,
        ]);
    }

    /** A spend the contract could not convert: `value` null, the original kept beside it. */
    private function withheldSpend(UnifiedCampaign $c, float $original, string $currency): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'value' => null, 'original_amount' => $original, 'original_currency' => $currency,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function spend(UnifiedCampaign $c, float $value, string $currency = 'SAR'): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => 'spend',
            'metric_date' => Carbon::now()->subDay()->toDateString(), 'value' => $value,
            'project_currency' => $currency,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function rule(): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'type' => 'budget_risk', 'name' => 'Budget', 'is_active' => true,
            'threshold' => ['ratio' => 0.9], 'channels' => ['in_app'], 'severity' => 'warning',
        ]);
    }

    /** The plain case still works — the gate must refuse the wrong figures, not stop alerting. */
    public function test_a_campaign_past_the_threshold_in_one_currency_still_alerts(): void
    {
        $c = $this->campaign(1_000);
        $this->spend($c, 950);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /**
     * The alert that never fired. The platform reported 5,000 against a 1,000 budget and no rate
     * could convert it, so the contract withheld the figure and `(float) null` read as «spent 0».
     *
     * Silence is the wrong answer, and so is a percentage. The budget cannot be monitored, and that
     * is the thing the operator needs told — their protection does not cover this campaign.
     */
    public function test_a_withheld_spend_raises_a_blind_spot_rather_than_passing_as_zero(): void
    {
        $c = $this->campaign(1_000);
        $this->withheldSpend($c, 5_000, 'USD');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));

        $event = AlertEvent::where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertSame('unmeasurable', $event->context['basis_class'] ?? null);
        $this->assertStringNotContainsString('%', (string) ($event->context['message'] ?? ''));
    }

    /**
     * The alert that fired about nothing. 3,750 SAR of spend against a 1,000 USD budget is roughly
     * on plan; divided as bare numbers it reads 375%, and the alarm names a figure that is not one.
     *
     * Same answer as the withheld case, for the same reason: the two numbers are not comparable, so
     * the budget is unmonitored and the alert says THAT.
     */
    public function test_a_spend_in_another_currency_is_not_divided_into_the_budget(): void
    {
        $c = $this->campaign(1_000, 'USD');
        $this->spend($c, 3_750, 'SAR');

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));

        $event = AlertEvent::where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertSame('unmeasurable', $event->context['basis_class'] ?? null);
        $this->assertStringNotContainsString('375', (string) ($event->context['message'] ?? ''));
    }

    /** A campaign under the threshold stays quiet — the blind-spot case must not alert on everything. */
    public function test_a_campaign_well_under_its_budget_stays_quiet(): void
    {
        $c = $this->campaign(1_000);
        $this->spend($c, 100);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }
}
