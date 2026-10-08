<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeResultAttribution;
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
 * CREATIVE-GRAIN-TRUTH-001 — a campaign's results are not the creatives' results.
 *
 * The owner, with a screenshot: a campaign reporting 109 purchases above creatives every one of
 * which read «الطلبات 0» and «0.00x» — «كيف حققت الحملة أداء عائد إلى 5x بالمقابل المحتويات العائد
 * لها ضعيف جداً، غير منطقي».
 *
 * Neither figure was wrong alone, which is what made the page unreadable. Several platforms report
 * a conversion against the CAMPAIGN and return a flat zero for it on each creative: the breakdown
 * was never made, so the zero means «not at this grain», not «nobody bought».
 *
 * The discrimination this has to get right is the one that costs a real figure if it goes the wrong
 * way: ONE creative at zero inside a selling campaign is an ordinary result and must stay a zero.
 * ALL of them at zero under a campaign that sold is a missing breakdown. The tests below are mostly
 * that boundary.
 */
final class CreativeResultAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => 'P',
            'status' => 'active',
        ]);
    }

    private function campaignDay(string $campaignId, float $conversions, float $revenue): void
    {
        DB::table('entity_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'entity_type' => 'campaign',
            'entity_id' => $campaignId,
            'external_entity_id' => 'ext-'.substr($campaignId, 0, 8),
            'attribution_window' => 'default',
            'is_demo' => false,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'conversions' => $conversions,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A real creative row, because `creative_daily_metrics.creative_id` is a foreign key. */
    private function creative(): string
    {
        $creative = ExternalCreative::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'external_creative_id' => 'ext-'.uniqid(),
            'name' => 'C-'.uniqid(),
            'format' => 'image',
        ]);

        return (string) $creative->getKey();
    }

    private function creativeDay(string $campaignId, string $creativeId, ?float $conversions, ?float $revenue): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaignId,
            'creative_id' => $creativeId,
            'is_demo' => false,
            'impressions' => 1000,
            'clicks' => 10,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'conversions' => $conversions,
            'revenue' => $revenue,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ask(array $campaignIds): array
    {
        return app(CreativeResultAttribution::class)->unattributedCampaigns(
            $campaignIds,
            Carbon::now()->subDays(7),
            Carbon::now(),
        );
    }

    /** The owner's case: the campaign sold, every creative under it reads zero. */
    public function test_a_campaign_that_sold_with_every_creative_at_zero_is_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609);
        $this->creativeDay($campaign, $this->creative(), 0, 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /**
     * The boundary that matters most.
     *
     * One creative sold and the others did not. The breakdown plainly exists, so the zeros are real
     * results and marking them unattributable would erase four true figures to avoid one awkward
     * comparison.
     */
    public function test_one_selling_creative_proves_the_breakdown_exists(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 109, revenue: 31_609);
        $this->creativeDay($campaign, $this->creative(), 24, 7_000);
        $this->creativeDay($campaign, $this->creative(), 0, 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /** Revenue alone is enough to prove it: a platform may break down value without a count. */
    public function test_revenue_without_a_count_still_proves_the_breakdown(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 40, revenue: 9_000);
        $this->creativeDay($campaign, $this->creative(), 0, 1_200);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /**
     * A campaign that sold nothing is not an attribution failure.
     *
     * An awareness buy was never asked to sell. Its creatives' zeros are contradicted by nothing,
     * and calling them unattributable would hide a true figure behind a caveat.
     */
    public function test_a_campaign_with_no_results_is_left_alone(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 0, revenue: 0);
        $this->creativeDay($campaign, $this->creative(), 0, 0);

        $this->assertSame([], $this->ask([$campaign]));
    }

    /** Absent is not zero here either: nulls at creative grain are no breakdown at all. */
    public function test_null_creative_results_under_a_selling_campaign_are_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 55, revenue: 12_000);
        $this->creativeDay($campaign, $this->creative(), null, null);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /** A campaign with no creative rows at all in the window is equally unattributed. */
    public function test_a_selling_campaign_with_no_creative_rows_is_not_attributed(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 12, revenue: 3_000);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    /** Each campaign is judged on its own evidence, never on its neighbour's. */
    public function test_campaigns_are_judged_separately(): void
    {
        $broken = (string) Str::uuid();
        $whole = (string) Str::uuid();

        $this->campaignDay($broken, conversions: 100, revenue: 20_000);
        $this->creativeDay($broken, $this->creative(), 0, 0);

        $this->campaignDay($whole, conversions: 30, revenue: 6_000);
        $this->creativeDay($whole, $this->creative(), 30, 6_000);

        $this->assertSame([$broken => true], $this->ask([$broken, $whole]));
    }

    /** Evidence outside the window proves nothing about it. */
    public function test_a_breakdown_outside_the_window_does_not_count(): void
    {
        $campaign = (string) Str::uuid();

        $this->campaignDay($campaign, conversions: 60, revenue: 15_000);

        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'campaign_id' => $campaign,
            'creative_id' => $this->creative(),
            'is_demo' => false,
            'impressions' => 1000,
            'clicks' => 10,
            /* Sold, but two months ago — outside the window being asked about. */
            'metric_date' => Carbon::now()->subDays(60)->toDateString(),
            'conversions' => 60,
            'revenue' => 15_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([$campaign => true], $this->ask([$campaign]));
    }

    public function test_no_campaigns_asked_is_no_query_and_no_answer(): void
    {
        $this->assertSame([], $this->ask([]));
    }
}
