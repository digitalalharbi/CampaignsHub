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
 * REPORT-SALES-FUNNEL-001 — Add to Cart is a figure on the KPI totals and on each platform row, not only on the funnel.
 *
 * The aggregator SUMMED the two basket steps but its derived-totals builder returned an explicit key list that left them out, so a
 * sales report's Add to Cart card read «no data» beside a funnel that showed 1,567. A platform that never sent them stays absent from
 * `reportedKeysByProvider` — the total is a sum of what was reported, and «not reported» is that map's job, not a zero's.
 */
final class SalesFunnelTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_basket_steps_are_in_the_totals_and_the_platform_rows(): void
    {
        $tenant = Tenant::create(['name' => 'A', 'slug' => 'sf-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $campaign = UnifiedCampaign::create(['project_id' => $project->id, 'name' => 'K', 'status' => 'active', 'objective' => 'sales']);
        foreach ([['meta', 'add_to_cart', 40], ['meta', 'checkout', 25], ['tiktok', 'add_to_cart', 60], ['meta', 'purchases', 10]] as [$provider, $key, $value]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => $provider,
                'metric_key' => $key, 'metric_date' => '2026-08-05', 'value' => $value,
            ]);
        }
        $engine = app(MetricsAggregator::class)->forProjects([(string) $project->id]);
        $from = Carbon::parse('2026-08-01');
        $to = Carbon::parse('2026-08-31');

        $totals = $engine->totals($from, $to);
        $this->assertEquals(100.0, $totals['add_to_cart']);
        $this->assertEquals(25.0, $totals['checkout']);
        $this->assertEquals(10.0, $totals['purchases'], 'purchases are counted once');

        $rows = collect($engine->byProvider($from, $to))->keyBy('provider');
        $this->assertEquals(40.0, $rows['meta']['add_to_cart']);
        $this->assertEquals(60.0, $rows['tiktok']['add_to_cart']);

        $reported = $engine->reportedKeysByProvider($from, $to);
        $this->assertTrue($reported['tiktok']['add_to_cart']);
        $this->assertFalse($reported['tiktok']['purchases'] ?? false, 'a platform that sent no purchases is not reported as having sent them');
    }
}
