<?php

declare(strict_types=1);

namespace Tests\Feature;

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
 * ALERT-SILENT-RULES-001 — CPA and CPL rules fire, and refuse to fire on money nobody knows.
 *
 * These two types were creatable, listed as active, and unevaluated. Wiring them is half the fix; the
 * other half is that a cost per result is MONEY, and this product has one rule about money.
 *
 * `spend` is `COALESCE(SUM(value), 0)`, so a window whose spend the provider withheld sums to zero and
 * `cpa` becomes `0 / conversions` = `0.00`. That is not a cheap campaign, it is an unknown one — and
 * comparing it against a converted window yields «CPA rose from 0.00 to 50.00», paging somebody at
 * night for a missing exchange rate. Both windows must be fully converted or there is no verdict.
 */
final class AlertCostPerIncreaseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function campaign(): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Camp '.uniqid(), 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => 0, 'budget_currency' => 'SAR',
        ]);
    }

    private function rule(string $type, float $pct = 25, int $days = 7): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => $type, 'name' => $type,
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning',
            'active' => true, 'threshold' => ['pct' => $pct, 'days' => $days],
        ]);
    }

    /** A converted row: the rate existed, `value` holds the money. */
    private function converted(UnifiedCampaign $c, string $key, float $value, int $daysAgo): void
    {
        $this->row($c, $key, $daysAgo, $value, null, null);
    }

    /** A WITHHELD row: no rate, so `value` is null and the original is preserved beside it. */
    private function withheld(UnifiedCampaign $c, float $original, int $daysAgo): void
    {
        $this->row($c, 'spend', $daysAgo, null, $original, 'USD');
    }

    private function row(UnifiedCampaign $c, string $key, int $daysAgo, ?float $value, ?float $original, ?string $currency): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => $key,
            'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
            'value' => $value, 'original_amount' => $original, 'original_currency' => $currency,
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    public function test_cpa_increase_fires_when_cost_per_conversion_rises_past_the_threshold(): void
    {
        $c = $this->campaign();
        // Previous window (8–14 days ago): 100 spend / 10 conversions = CPA 10.
        $this->converted($c, 'spend', 100, 10);
        $this->converted($c, 'conversions', 10, 10);
        // Current window (0–7 days ago): 100 spend / 4 conversions = CPA 25 — a 150% rise.
        $this->converted($c, 'spend', 100, 3);
        $this->converted($c, 'conversions', 4, 3);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')));
    }

    public function test_cpa_increase_stays_quiet_when_the_rise_is_below_the_threshold(): void
    {
        $c = $this->campaign();
        $this->converted($c, 'spend', 100, 10);
        $this->converted($c, 'conversions', 10, 10);   // CPA 10
        $this->converted($c, 'spend', 105, 3);
        $this->converted($c, 'conversions', 10, 3);    // CPA 10.5 — a 5% rise

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')));
    }

    public function test_cpa_increase_refuses_a_partial_previous_window(): void
    {
        /*
         * The case the guard actually exists for, and the hardest one to see.
         *
         * A FULLY withheld window sums to zero, and the «previous cost was zero» check already
         * refuses that — so a test built on one proves nothing about this guard. A PARTIAL window is
         * different: 50 converted beside 500 withheld sums to 50, and `cpa` reads 5.00 — a
         * real-looking figure understated by an order of magnitude because most of the spend had no
         * rate. Against a genuinely converted current window at 10.00 that reads as «CPA doubled»,
         * and the campaign did nothing of the sort.
         *
         * Remove the withheld check and this fires. That is what makes it load-bearing.
         */
        $c = $this->campaign();
        $this->converted($c, 'spend', 50, 10);
        $this->withheld($c, 500, 10);
        $this->converted($c, 'conversions', 10, 10);   // cpa reads 5.00 from a subset
        $this->converted($c, 'spend', 100, 3);
        $this->converted($c, 'conversions', 10, 3);    // cpa 10.00 — a fabricated 100% "rise"

        $this->assertSame(
            0,
            app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')),
            'A partial previous window understates CPA; the "rise" is the missing rate arriving.'
        );
    }

    public function test_cpa_increase_refuses_a_partial_current_window(): void
    {
        // The mirror. A threshold check cannot catch this one either, because the understated figure
        // still sits above the previous window's.
        $c = $this->campaign();
        $this->converted($c, 'spend', 10, 10);
        $this->converted($c, 'conversions', 10, 10);   // cpa 1.00
        $this->converted($c, 'spend', 50, 3);
        $this->withheld($c, 900, 3);
        $this->converted($c, 'conversions', 10, 3);    // cpa reads 5.00 — a 400% "rise" from a subset

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')));
    }

    public function test_a_fully_withheld_window_is_also_refused(): void
    {
        // The zero-cost check would catch this one anyway, but it must never be the ONLY thing
        // between a missing FX rate and somebody's phone at 2am.
        $c = $this->campaign();
        $this->withheld($c, 500, 10);
        $this->converted($c, 'conversions', 10, 10);
        $this->converted($c, 'spend', 100, 3);
        $this->converted($c, 'conversions', 4, 3);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')));
    }

    public function test_cpl_increase_is_evaluated_on_leads_not_conversions(): void
    {
        $c = $this->campaign();
        $this->converted($c, 'spend', 100, 10);
        $this->converted($c, 'leads', 20, 10);         // CPL 5
        $this->converted($c, 'spend', 200, 3);
        $this->converted($c, 'leads', 10, 3);          // CPL 20 — a 300% rise

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('cpl_increase')));
    }

    public function test_no_verdict_is_issued_from_a_zero_previous_cost(): void
    {
        $c = $this->campaign();
        // Conversions with no spend at all: CPA 0. Every rise from zero is infinite, and «up ∞%» is
        // not a threshold anyone set.
        $this->converted($c, 'conversions', 10, 10);
        $this->converted($c, 'spend', 100, 3);
        $this->converted($c, 'conversions', 4, 3);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('cpa_increase')));
    }
}
