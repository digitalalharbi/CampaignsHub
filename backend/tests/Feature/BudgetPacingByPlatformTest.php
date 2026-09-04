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
 * CLIENT-REPORT-ENTITY-BOUNDARY-001 — pacing a client can read, folded to the platform.
 *
 * «هل شيء على وشك النفاد؟» is the one question on a client link that can still be acted on before the
 * period ends, and the block that answered it answered by naming the agency's own campaigns. The fold
 * to platform keeps the question and drops the plan.
 *
 * The arithmetic is what this file is actually about. A fold is where a true per-campaign figure
 * quietly becomes a false aggregate one, in three specific ways, and each has its own test below:
 * a budget summed across campaigns that do not all HAVE one; a sum stated in a unit only part of it
 * is in; and a campaign whose spend landed on two platforms, counted once per platform.
 */
final class BudgetPacingByPlatformTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'pace-a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'pace-c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    private function campaign(string $name, ?float $budget, string $currency = 'SAR'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'project_id' => $this->project->id,
            'name' => $name,
            'status' => 'active',
            'total_budget' => $budget,
            'budget_currency' => $currency,
        ]);
    }

    private function spend(UnifiedCampaign $campaign, string $provider, float $value, string $currency = 'SAR'): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->id,
            'provider' => $provider,
            'metric_key' => 'spend',
            'metric_date' => '2026-07-10',
            'value' => $value,
            'project_currency' => $currency,
        ]);
    }

    /** @return array<string, array<string, mixed>> the rows, keyed by platform */
    private function pacing(): array
    {
        $rows = app(MetricsAggregator::class)
            ->forProjects([$this->project->id])
            ->budgetPacingByProvider(
                Carbon::parse('2026-07-01'),
                Carbon::parse('2026-07-31'),
                // Half the period elapsed: 15 of 31 days, so an on-plan campaign paces near 1.0×.
                Carbon::parse('2026-07-15'),
            );

        return collect($rows)->keyBy('provider')->all();
    }

    public function test_it_sums_the_plan_and_the_spend_of_one_platform(): void
    {
        $this->spend($this->campaign('Brand', 4000.0), 'meta', 1000.0);
        $this->spend($this->campaign('Retargeting', 6000.0), 'meta', 1400.0);

        $meta = $this->pacing()['meta'];

        $this->assertSame(10000.0, $meta['budget']);
        $this->assertSame(2400.0, $meta['spent']);
        $this->assertSame('SAR', $meta['budget_currency']);
        $this->assertSame('comparable', $meta['pacing_basis']);
        $this->assertSame(7600.0, $meta['remaining']);
        // 2,400 spent against 4,838.71 expected by day 15 of 31 — behind plan, and it says so.
        $this->assertEqualsWithDelta(0.496, $meta['pace'], 0.002);
    }

    /** The plan does not travel: no name, no id, nothing to reconstruct the roster from. */
    public function test_a_folded_row_carries_no_campaign_identity(): void
    {
        $budgeted = $this->campaign('White Friday — Prospecting', 4000.0);
        $this->spend($budgeted, 'meta', 1000.0);

        $json = json_encode($this->pacing(), JSON_UNESCAPED_UNICODE) ?: '';

        $this->assertStringNotContainsString('White Friday', $json);
        $this->assertStringNotContainsString($budgeted->id, $json);
        $this->assertArrayNotHasKey('campaign_name', $this->pacing()['meta']);
    }

    /**
     * Spend with no plan behind it makes the whole bucket unpaceable.
     *
     * The tempting answer is to pace the budgeted half and ignore the rest, which reports «0.5×,
     * comfortably under plan» to a client while an unbudgeted campaign spends beside it. The figures
     * still travel — they are true sums — and only the ratio is refused.
     */
    public function test_a_platform_refuses_to_pace_spend_that_has_no_plan(): void
    {
        $this->spend($this->campaign('Budgeted', 4000.0), 'meta', 1000.0);
        $this->spend($this->campaign('Unbudgeted', null), 'meta', 3000.0);

        $meta = $this->pacing()['meta'];

        $this->assertSame('no_budget', $meta['pacing_basis']);
        $this->assertNull($meta['pace']);
        $this->assertNull($meta['consumed_pct']);
        $this->assertNull($meta['remaining']);
        $this->assertSame(4000.0, $meta['spent'], 'the sums are true even where the ratio is refused');
    }

    /** Two units, one bucket: a sum in neither of them is not an answer. */
    public function test_a_platform_refuses_to_pace_across_two_currencies(): void
    {
        $this->spend($this->campaign('Riyals', 4000.0, 'SAR'), 'meta', 1000.0);
        $this->spend($this->campaign('Dollars', 1000.0, 'USD'), 'meta', 400.0);

        $this->assertSame('currency_mismatch', $this->pacing()['meta']['pacing_basis']);
        $this->assertNull($this->pacing()['meta']['pace']);
    }

    /**
     * One campaign, two platforms — its spend is split between them and its plan is not divided.
     *
     * The fold is by platform and the budget is held by the campaign, so a campaign running on Meta
     * and Google has a plan that belongs to neither bucket alone. Splitting it in proportion to spend
     * would invent a plan nobody set. Counting it in both would state twice the money as planned.
     */
    public function test_a_campaign_spanning_two_platforms_is_split_not_doubled(): void
    {
        $both = $this->campaign('Everywhere', 9000.0);
        $this->spend($both, 'meta', 600.0);
        $this->spend($both, 'google', 400.0);

        $rows = $this->pacing();

        $this->assertSame(600.0, $rows['meta']['spent'], 'the platform took the campaign’s whole spend');
        $this->assertSame(400.0, $rows['google']['spent']);
        $this->assertSame(0.0, $rows['meta']['budget'], 'an undividable plan was divided anyway');
        $this->assertSame('campaign_spans_platforms', $rows['meta']['pacing_basis']);
        $this->assertSame('campaign_spans_platforms', $rows['google']['pacing_basis']);
    }
}
