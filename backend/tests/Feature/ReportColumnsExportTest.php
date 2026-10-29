<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * REPORT-SECTION-SURFACES-001 — one resolver, and every surface renders the same section set.
 *
 * The same client report is opened through the shared link, the print route the PDF is rendered
 * from, and the data the file export is built from. Each carries `report_sections`, and the three
 * must be the same list — with one hidden section for each of the three reasons, and the hidden
 * sections' data absent from every one of them.
 */
final class ReportColumnsExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

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

    private function share(array $columns): ReportShare
    {
        [$share] = app(ShareService::class)->create($this->snapshotReport(), ['allow_download' => true, 'settings' => ['columns' => $columns]], null);

        return $share;
    }

    /** @return array<int, array<int, string|null>> */
    private function csvRows(string $csv): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", $csv), static fn ($l) => $l !== ''));
    }

    /** The CSV block that starts at the line whose first cell is $title, up to the next blank line. */
    private function csvBlock(string $csv, string $title): array
    {
        $block = [];
        $inside = false;
        foreach (explode("\n", $csv) as $line) {
            $cells = str_getcsv($line, ',', '"', '');
            if (! $inside && ($cells[0] ?? null) === $title) {
                $inside = true;
            }
            if ($inside) {
                if (trim($line) === '') {
                    break;
                }
                $block[] = $cells;
            }
        }

        return $block;
    }

    public function test_the_csv_cuts_the_platform_and_daily_tables_to_the_chosen_columns(): void
    {
        $share = $this->share(['platforms' => ['spend', 'results'], 'days' => ['spend']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $csv = app(ReportExporter::class)->render($share->report()->withoutGlobalScopes()->first(), 'csv', $share);

        $platforms = $this->csvBlock($csv, 'Platforms');
        $this->assertSame(['Platforms', 'spend', 'conversions'], $platforms[0], 'name column first, then only what was chosen, in table order');
        $this->assertSame(['meta', '700', '20'], $platforms[1]);

        $days = $this->csvBlock($csv, 'Daily Records');
        $this->assertSame(['Daily Records', 'Spend'], $days[0]);
        $this->assertCount(4, $days, 'header plus the three days');
    }

    public function test_the_xlsx_sheets_follow_the_same_choice(): void
    {
        $share = $this->share(['platforms' => ['cost', 'ctr'], 'days' => ['results']]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $bytes = app(ReportExporter::class)->render($share->report()->withoutGlobalScopes()->first(), 'xlsx', $share);

        $tmp = tempnam(sys_get_temp_dir(), 'cols_').'.xlsx';
        file_put_contents($tmp, $bytes);
        $book = IOFactory::load($tmp);
        @unlink($tmp);

        $this->assertSame(['Platform', 'CPA', 'CTR'], array_values(array_filter($book->getSheetByName('Platforms')->toArray()[0])));
        // RESULT-EVENTS-001 — the ledger has no «Results» column: the resolver has no daily figure and generic conversions are not one.
        $this->assertSame(['Date'], array_values(array_filter($book->getSheetByName('Daily Records')->toArray()[0])));
    }

    public function test_a_link_with_no_choice_exports_every_column_as_before(): void
    {
        [$share] = app(ShareService::class)->create($this->snapshotReport(), ['allow_download' => true], null);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $csv = app(ReportExporter::class)->render($share->report()->withoutGlobalScopes()->first(), 'csv', $share);

        $this->assertSame(['Platforms', 'spend', 'revenue', 'conversions', 'roas', 'cpa', 'ctr', 'share'], $this->csvBlock($csv, 'Platforms')[0]);
        $this->assertSame(['Daily Records', 'Impressions', 'Clicks', 'Spend'], $this->csvBlock($csv, 'Daily Records')[0]);
    }

    public function test_the_print_route_and_the_shared_link_carry_the_choice_for_the_pdf_and_the_page(): void
    {
        $report = $this->snapshotReport();
        [, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'settings' => ['columns' => ['platforms' => ['spend']]]], null);

        $shared = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');
        $this->assertSame(['platforms' => ['spend']], $shared['report_columns']);
    }
}
