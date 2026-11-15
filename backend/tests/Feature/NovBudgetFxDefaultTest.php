<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Services\SpendLimitGovernor;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * Nov overhaul, task 6, as superseded by FX-ENGINE-001: the USD→SAR rate is the PLATFORM's (the SAR peg, a pegged row managed
 * at /admin/settings/currency-rates). It still resolves «ناقص سعر صرف» and lets a limit in another currency be measured; a
 * workspace can read it and can no longer override it.
 */
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

    public function test_a_workspace_can_no_longer_override_the_rate_it_is_managed_by_the_platform(): void
    {
        $project = $this->newProject('Palm');
        $this->limitSpend($project, 3, 100, 'USD', 'SAR', value: 375);
        $this->addBudget($project, 10_000);

        $this->actingAs($this->finance, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 3.8])->assertStatus(410);

        $this->assertSame(375.0, (float) $this->figures($project->id)['spent'], 'nothing a workspace sends moves its own figures');
        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/funding/rate')->assertOk()
            ->assertJsonPath('data.usd_sar', 3.75)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.managed_by', 'platform')
            ->assertJsonPath('data.kind', 'pegged')
            ->assertJsonPath('data.rates_page', '/admin/settings/currency-rates');
    }

    public function test_any_reader_of_budgets_sees_the_platform_rate_and_nobody_in_a_workspace_can_set_it(): void
    {
        $viewer = $this->userWith(['funding.view']);
        $this->actingAs($viewer, 'sanctum')->putJson('/api/v1/funding/rate', ['usd_sar' => 3.9])->assertStatus(410);
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
        $this->assertSame('pegged', $row['converted']['kind']);
        $this->assertSame('official-peg:SAMA', $row['converted']['source']);
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
