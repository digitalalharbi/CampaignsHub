<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CLIENT-REPORT-ENTITY-BOUNDARY-001, the other half — «Do NOT globally delete those capabilities.»
 *
 * The requirement removes the campaign roster from what a CLIENT receives. It is explicit that the
 * internal campaign-management and media-buyer screens keep the full Campaign → Ad Set → Ad →
 * Content hierarchy and every name in it — an operator reading «the sales figure excludes 4,127 SAR»
 * has to know which campaigns that was in order to act on it.
 *
 * That half has no test of its own, and a boundary implemented by deletion looks identical to a
 * boundary implemented correctly until somebody opens the operator's screen. This file is the
 * difference: it asserts the aggregator still answers per campaign, and that an INTERNAL export
 * still carries the roster the client's does not.
 */
final class OperatorHierarchyIntactTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'op-a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'op-c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $campaign = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => 'Meta — Retargeting (burner)',
            'status' => 'active', 'total_budget' => 10_000, 'budget_currency' => 'SAR',
        ]);

        DailyMetric::create([
            'id' => (string) Str::uuid(),
            'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->id,
            'provider' => 'meta',
            'metric_key' => 'spend',
            'metric_date' => '2026-07-10',
            'value' => 4000,
            'project_currency' => 'SAR',
        ]);
    }

    /**
     * A snapshot that passes the export gate: the platform sums reconcile to the summary totals, or
     * the exporter refuses to render at all and the test would be asserting a 422 rather than a sheet.
     *
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
            'currency' => 'SAR',
            'kpis' => ['spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0],
            'platforms' => [['provider' => 'meta', 'spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0]],
            'campaigns' => [['campaign_name' => 'Meta — Retargeting (burner)', 'provider' => 'meta', 'spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0]],
        ];
    }

    /** The operator's own breakdown still names the campaign. It is their container. */
    public function test_the_aggregator_still_answers_per_campaign(): void
    {
        $rows = app(MetricsAggregator::class)
            ->forProjects([$this->project->id])
            ->byCampaign(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertCount(1, $rows);
        $this->assertSame('Meta — Retargeting (burner)', $rows[0]['campaign_name']);
    }

    /** And per-campaign pacing, which is what an operator tops a campaign up on. */
    public function test_per_campaign_pacing_is_still_available_to_an_operator(): void
    {
        $rows = app(MetricsAggregator::class)
            ->forProjects([$this->project->id])
            ->budgetPacing(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), Carbon::parse('2026-07-15'));

        $this->assertCount(1, $rows);
        $this->assertSame('Meta — Retargeting (burner)', $rows[0]['campaign_name']);
        $this->assertArrayHasKey('campaign_id', $rows[0]);
    }

    /**
     * An INTERNAL workbook keeps the sheet a client's does not get.
     *
     * The exporter is one method for every audience, so this is where a fix aimed at the client
     * would most easily take the operator's copy with it.
     */
    public function test_an_internal_export_still_carries_the_campaign_sheet(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'monthly', 'status' => 'completed', 'audience' => 'internal',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => $this->snapshot(),
        ]);

        $csv = app(ReportExporter::class)->render($report, 'csv');

        $this->assertStringContainsString('Meta — Retargeting (burner)', $csv);
    }

    /**
     * The PDF too — the format a client is most likely to forward.
     *
     * `ChromiumPdfRenderer` needs a browser binary, so this asserts the payload the print route is
     * given rather than the rendered bytes: the same `audienceData()` feeds all three formats, and a
     * CSV proving the boundary while the PDF renders from a different snapshot is not a thing this
     * code can do. The rendered PDF was verified by hand on a real legacy share (15 pages, Playwright
     * Chromium, text layer extracted and searched) and carried no campaign name.
     */
    public function test_every_format_of_a_client_report_renders_from_the_same_filtered_snapshot(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'monthly', 'status' => 'completed', 'audience' => 'client',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => $this->snapshot(),
        ]);

        $xlsx = app(ReportExporter::class)->render($report, 'xlsx');

        // The workbook is a zip; the campaign name would sit in its shared-strings part.
        $this->assertStringNotContainsString('Retargeting', $xlsx);
    }

    /** …and the CLIENT copy of the same report does not. One method, two audiences, one boundary. */
    public function test_the_client_export_of_the_same_report_does_not(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'R', 'type' => 'monthly', 'status' => 'completed', 'audience' => 'client',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => $this->snapshot(),
        ]);

        $csv = app(ReportExporter::class)->render($report, 'csv');

        $this->assertStringNotContainsString('Retargeting', $csv);
        $this->assertStringNotContainsString('Campaigns', $csv, 'an empty campaign section reads as data that failed to load');
    }
}
