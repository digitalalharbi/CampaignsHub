<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Services\ReportAds;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-BEST-NOT-SPEND-001 — a report whose campaigns span objectives never titles its biggest bills
 * «أفضل المحتوى أداءً».
 *
 * The Resano link: the scope mixed sales and awareness campaigns, the scope-wide order rested on spend,
 * and the four «best» were the four most expensive ads, two with no result on the card. The headline
 * lists now come from the leading objective family, ranked on that family's own metric.
 */
final class ReportBestNotSpendTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Project 1', 'status' => 'active',
        ]);
    }

    /** @param list<array{name: string, spend: float, conversions: int, revenue: float, impressions: int}> $creatives */
    private function campaign(string $objective, string $externalId, array $creatives): void
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta',
            'external_id' => $externalId, 'name' => 'Campaign '.$externalId, 'status' => 'active', 'objective' => $objective,
        ]);
        foreach ($creatives as $i => $c) {
            $creative = ExternalCreative::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'provider' => 'meta', 'external_creative_id' => $externalId.'-'.$i, 'name' => $c['name'], 'format' => 'image', 'asset_url' => 'https://cdn.test/a.jpg',
            ]);
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'creative_id' => $creative->id, 'metric_date' => '2026-07-15',
                'spend' => $c['spend'], 'impressions' => $c['impressions'], 'clicks' => 50, 'conversions' => $c['conversions'], 'revenue' => $c['revenue'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_a_mixed_objective_report_headlines_the_leading_familys_best_and_worst_not_the_biggest_bills(): void
    {
        $this->campaign('sales', 'sales-1', [
            ['name' => 'Sells well', 'spend' => 400.0, 'conversions' => 40, 'revenue' => 2400.0, 'impressions' => 90_000],
            ['name' => 'Sells little', 'spend' => 380.0, 'conversions' => 2, 'revenue' => 100.0, 'impressions' => 80_000],
            ['name' => 'Sells some', 'spend' => 200.0, 'conversions' => 10, 'revenue' => 700.0, 'impressions' => 50_000],
        ]);
        $this->campaign('awareness', 'aware-1', [
            ['name' => 'Biggest bill', 'spend' => 900.0, 'conversions' => 0, 'revenue' => 0.0, 'impressions' => 400_000],
        ]);

        $section = app(ReportAds::class)->for('custom', Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), ['project_ids' => [(string) $this->project->id]]);

        $this->assertSame('sales', $section['basis']['family']);
        $this->assertNotSame('spend', $section['basis']['metric']);
        $this->assertSame('Sells well', $section['ads'][0]['name'], 'the best is the best seller, not the biggest bill');
        $this->assertNotContains('Biggest bill', array_column($section['ads'], 'name'));
        $this->assertSame($section['basis']['metric'], $section['ads'][0]['reason_basis']);
        $this->assertSame('Sells little', $section['worst'][0]['name'], 'the one to improve is the weakest seller, not the cheapest ad');
        $this->assertNotContains('Biggest bill', array_column($section['worst'], 'name'));
    }

    public function test_a_single_objective_report_keeps_its_own_order_and_names_the_basis(): void
    {
        $this->campaign('sales', 'sales-1', [
            ['name' => 'A', 'spend' => 400.0, 'conversions' => 40, 'revenue' => 2400.0, 'impressions' => 90_000],
            ['name' => 'B', 'spend' => 200.0, 'conversions' => 10, 'revenue' => 700.0, 'impressions' => 50_000],
        ]);

        $section = app(ReportAds::class)->for('sales', Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), ['project_ids' => [(string) $this->project->id]]);

        $this->assertNull($section['basis']['family']);
        $this->assertSame('roas', $section['basis']['metric']);
        $this->assertSame('A', $section['ads'][0]['name']);
    }
}
