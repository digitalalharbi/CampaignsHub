<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-OPTION-TRAVEL-001 — «if a UI option changes nothing: fix it, remove it, or disable it».
 *
 * A report's `config.slides` carries a `visible` flag, and both renderers honour it: `InteractiveReport`
 * and `PrintReport` each filter `s.visible` before drawing. `config` is a free-form array accepted on
 * create and on update, so a hidden section is reachable through the API today.
 *
 * The spreadsheet and the CSV are built from the DATA keys instead — `$data['funnel']`,
 * `$data['campaigns']` — and never look at the slides. So a section a reader turned off travels to
 * the web view and the PDF and not to the file, which is the owner's clause exactly.
 *
 * It matters more than a missing sheet, because the exporter's own comment states the rule this
 * breaks: «what the client cannot see must not be in the bytes they were sent». A hidden section
 * still shipped its figures.
 */
final class HiddenSectionLeavesTheBytesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->getKey());

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    public function test_a_hidden_section_is_not_in_the_csv(): void
    {
        $csv = app(ReportExporter::class)->render($this->report(campaignsVisible: false), 'csv');

        $this->assertStringNotContainsString('Meta — Retargeting', $csv, 'a section the reader hid still shipped its figures');
    }

    public function test_a_hidden_section_is_not_in_the_workbook(): void
    {
        $xlsx = app(ReportExporter::class)->render($this->report(campaignsVisible: false), 'xlsx');

        $this->assertStringNotContainsString('Meta — Retargeting', $xlsx);
    }

    /**
     * And the visible case is unchanged — the point is the flag, not a narrower export.
     *
     * Without this, «hidden sections are gone» could be satisfied by an export that dropped the
     * section for everybody, which is a worse defect wearing the fix's name.
     */
    public function test_a_visible_section_still_ships(): void
    {
        $csv = app(ReportExporter::class)->render($this->report(campaignsVisible: true), 'csv');

        $this->assertStringContainsString('Meta — Retargeting', $csv);
    }

    /** A report with no slide list at all keeps every section: absent is not hidden. */
    public function test_a_report_without_a_slide_list_keeps_everything(): void
    {
        $report = $this->report(campaignsVisible: true);
        $report->update(['config' => []]);

        $this->assertStringContainsString('Meta — Retargeting', app(ReportExporter::class)->render($report, 'csv'));
    }

    private function report(bool $campaignsVisible): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'monthly', 'status' => 'completed', 'audience' => 'internal',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'config' => ['slides' => [
                ['id' => 'kpis', 'type' => 'kpis', 'order' => 1, 'visible' => true],
                ['id' => 'campaigns', 'type' => 'campaigns', 'order' => 2, 'visible' => $campaignsVisible],
            ]],
            'data' => [
                'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
                'currency' => 'SAR',
                'kpis' => ['spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0],
                // The consistency gate refuses a snapshot whose platforms do not sum to the summary.
                'platforms' => [['provider' => 'meta', 'spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0]],
                'campaigns' => [[
                    'campaign_name' => 'Meta — Retargeting', 'provider' => 'meta',
                    'spend' => 4000.0, 'revenue' => 0.0, 'conversions' => 0.0,
                ]],
            ],
        ]);
    }
}
