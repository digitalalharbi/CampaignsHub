<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\SectionContext;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-SECTION-SURFACES-001 — one resolver, and every surface renders the same section set.
 *
 * The same client report is opened through the shared link, the print route the PDF is rendered
 * from, and the data the file export is built from. Each carries `report_sections`, and the three
 * must be the same list — with one hidden section for each of the three reasons, and the hidden
 * sections' data absent from every one of them.
 */
final class ReportSectionParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private const VISIBLE = ['kpis', 'platform_comparison', 'funnel', 'recommendations', 'objective_breakdown'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Parity', 'slug' => 'parity-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales push', 'status' => 'active', 'objective' => 'sales',
        ]);
    }

    /** A completed client snapshot where each hidden section has a different reason. */
    private function snapshotReport(): Report
    {
        $data = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'currency' => 'SAR',
            'objective' => 'sales',
            'kpis' => ['spend' => 1200.0, 'conversions' => 30.0, 'impressions' => 90000.0, 'clicks' => 900.0],
            'timeseries' => [
                ['date' => '2026-08-01', 'spend' => 400.0], ['date' => '2026-08-02', 'spend' => 400.0], ['date' => '2026-08-03', 'spend' => 400.0],
            ],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 700.0, 'conversions' => 20.0],
                ['provider' => 'snapchat', 'spend' => 500.0, 'conversions' => 10.0],
            ],
            // data_unavailable: no platform carries a budget.
            'budget' => [['provider' => 'meta', 'budget' => 0.0, 'spent' => 700.0]],
            'funnel' => [['stage' => 'impressions', 'label' => 'Impressions', 'reported' => true, 'count' => 90000]],
            // unsupported_by_provider_or_objective: no metric ranks ads honestly here.
            'ads' => [],
            'ads_roster' => [],
            'ads_absent_reason' => 'no_rankable_metric_for_this_objective',
            'recommendations' => [['title' => 'Shift budget to Meta', 'status' => 'approved']],
            'objective_leaders' => ['paths' => [['path' => 'direct_sales', 'comparable' => false]]],
            'objective_performance' => ['paths' => [['path' => 'direct_sales', 'spend' => 1200.0]]],
            'slides' => [
                ['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true],
                ['id' => 'summary', 'type' => 'executive_summary', 'order' => 2, 'visible' => true],
                ['id' => 'cmp', 'type' => 'platform_comparison', 'order' => 3, 'visible' => true],
                ['id' => 'budget', 'type' => 'budget', 'order' => 4, 'visible' => true],
                ['id' => 'funnel', 'type' => 'funnel', 'order' => 5, 'visible' => true],
                ['id' => 'ads', 'type' => 'ads', 'order' => 6, 'visible' => true],
                ['id' => 'split', 'type' => 'objective_performance', 'order' => 7, 'visible' => true],
                ['id' => 'recs', 'type' => 'recommendations', 'order' => 8, 'visible' => true],
            ],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
            'data' => $data,
            // disabled_by_operator: the operator switched the trends off.
            'section_settings' => ['sections' => ['trends' => false]],
        ]);
    }

    public function test_the_shared_link_the_print_route_and_the_export_carry_the_same_sections(): void
    {
        $report = $this->snapshotReport();

        [, $raw] = app(ShareService::class)->create($report, ['allow_download' => true], null);
        $shared = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');

        $token = Str::random(48);
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'watermark' => false,
        ], 300);
        $printed = $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');

        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $exported = app(ReportExporter::class)->exportData($report->fresh());

        foreach (['shared link' => $shared, 'print route' => $printed, 'export' => $exported] as $surface => $payload) {
            $this->assertSame(self::VISIBLE, $payload['report_sections'], "{$surface} renders a different section set");

            // Hidden means absent — for each of the three reasons.
            $this->assertArrayNotHasKey('budget', $payload, "{$surface}: data_unavailable budget still travels");
            $this->assertArrayNotHasKey('ads_absent_reason', $payload, "{$surface}: unsupported content still travels");
            $this->assertArrayNotHasKey('objective_performance', $payload, "{$surface}: advanced segmentation is off by default");
            $this->assertArrayNotHasKey('platform_series', $payload, "{$surface}: trends were switched off");

            // The deck's slides for hidden sections are gone too.
            $types = array_column($payload['slides'], 'type');
            $this->assertNotContains('budget', $types, $surface);
            $this->assertNotContains('ads', $types, $surface);
            $this->assertNotContains('objective_performance', $types, $surface);
            $this->assertContains('platform_comparison', $types, $surface);
        }

        // The file actually downloads through the same path.
        $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk();
    }

    public function test_the_live_link_resolves_through_the_same_resolver_with_each_reason(): void
    {
        foreach ([['spend', 400.0], ['clicks', 90.0], ['impressions', 9000.0], ['conversions', 12.0]] as $i => [$key, $value]) {
            foreach ([2, 3] as $daysAgo) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                    'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                    'unified_campaign_id' => $this->campaign->getKey(), 'provider' => 'meta', 'metric_key' => $key,
                    'metric_date' => now()->subDays($daysAgo)->toDateString(), 'value' => $value,
                ]);
            }
        }

        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Live', 'type' => 'live', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'SAR',
            // disabled_by_operator on the report, and on the link below.
            'section_settings' => ['sections' => ['trends' => false]],
        ]);

        // unsupported_by_provider_or_objective, registered the way another lane would.
        app(ReportSectionRegistry::class)->supportWhen('funnel', 'test_provider_cannot', static fn (SectionContext $c): bool => false);

        [$share, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);
        $share->settings = ['sections' => ['platform_comparison' => false]];
        $share->save();

        $payload = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $this->assertContains('kpis', $payload['report_sections']);
        $this->assertNotContains('trends', $payload['report_sections'], 'report operator choice');
        $this->assertNotContains('platform_comparison', $payload['report_sections'], 'link operator choice');
        $this->assertNotContains('funnel', $payload['report_sections'], 'unsupported');
        $this->assertNotContains('budget_pacing', $payload['report_sections'], 'no budget: data unavailable');

        $this->assertArrayNotHasKey('platforms', $payload);
        $this->assertArrayNotHasKey('funnel', $payload);
        $this->assertArrayNotHasKey('budget', $payload);
        $this->assertArrayNotHasKey('objective_performance', $payload);
        $this->assertArrayHasKey('totals', $payload);
        // The KPI sparklines still need the series the trend chart would have drawn.
        $this->assertArrayHasKey('timeseries', $payload);

        // The content endpoints follow the report, not only the link.
        $report->update(['section_settings' => ['sections' => ['content_performance' => false]]]);
        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/anything")->assertNotFound();
    }
}
