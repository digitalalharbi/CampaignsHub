<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Services\SpendLimitGovernor;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/** Nov overhaul, task 6 — the workspace default USD→SAR rate resolves «ناقص سعر صرف» and lets a limit in another currency be measured. */
final class NovBudgetFxDefaultTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bootFunding();
    }

    private function figures(string $projectId): array
    {
        return (array) $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/projects/'.$projectId)->assertOk()->json('data');
    }

    public function test_usd_spend_with_no_dated_rate_uses_the_default_and_the_budget_is_not_incomplete(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 3, 100, 'USD', 'SAR', value: 375);
        // the funding spend rows are written by the refresher from daily_metrics with the platform's own USD figure
        $this->addBudget($project, 10_000);

        $f = $this->figures($project->id);

        $this->assertSame(375.0, (float) $f['spent']);
        $this->assertSame(0, $f['spend_without_rate']);
        $this->assertNotSame('incomplete', $f['status']);
        $this->assertSame(3.75, (float) $f['usd_rate']);
    }

    public function test_the_default_is_editable_by_finance_and_recomputes_the_budget(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 3, 100, 'USD', 'SAR', value: 375);
        $this->addBudget($project, 10_000);

        $this->actingAs($this->finance, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 3.8])->assertOk()
            ->assertJsonPath('data.usd_sar', 3.8)->assertJsonPath('data.custom', true);

        $this->assertSame(380.0, (float) $this->figures($project->id)['spent']);
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/rate')->assertOk()->assertJsonPath('data.usd_sar', 3.8);
    }

    public function test_the_rate_is_validated_and_needs_the_finance_permission(): void
    {
        $this->actingAs($this->finance, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 0])->assertStatus(422);
        $this->actingAs($this->finance, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 'abc'])->assertStatus(422);

        $viewer = $this->userWith(['funding.view']);
        $this->actingAs($viewer, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 3.9])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/funding/rate')->assertOk()->assertJsonPath('data.usd_sar', 3.75)->assertJsonPath('data.custom', false);
    }

    public function test_a_usd_limit_over_sar_spend_is_converted_and_measured(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 2, 750, 'SAR', 'SAR');
        $limit = $this->makeLimit($project, 1_000, 'USD');

        $row = app(SpendLimitGovernor::class)->read($limit, Carbon::today());

        $this->assertSame('comparable', $row['basis']);
        $this->assertSame('USD', $row['consumed_currency']);
        $this->assertSame(200.0, $row['consumed']);
        $this->assertSame(0.2, $row['utilisation']);
        $this->assertSame('workspace_default', $row['converted']['source']);
    }

    public function test_a_limit_in_a_floating_currency_without_a_rate_is_still_refused(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 2, 750, 'SAR', 'SAR');
        $limit = $this->makeLimit($project, 1_000, 'EUR');

        $row = app(SpendLimitGovernor::class)->read($limit, Carbon::today());

        $this->assertSame('unknown', $row['state']);
        $this->assertSame('currency_mismatch', $row['basis']);
    }

    public function test_the_limits_list_tells_the_page_which_currency_a_new_limit_starts_in(): void
    {
        $owner = $this->owner();
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 2, 750, 'SAR', 'SAR');

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/projects/'.$project->id.'/spend-limits')->assertOk()
            ->assertJsonPath('meta.project_currency', 'SAR');

        $other = $this->newProject('Dollar');
        $this->limitSpend($other, 2, 100, 'USD', 'USD');
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/projects/'.$other->id.'/spend-limits')->assertOk()
            ->assertJsonPath('meta.project_currency', 'USD');
    }
}
