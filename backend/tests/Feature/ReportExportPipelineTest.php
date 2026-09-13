<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\NarrativeConsistencyValidator;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The export pipeline the client actually downloads: client PDFs MUST use Chromium (never a Dompdf
 * fallback), narrative must agree with the snapshot, and stale exports must not be downloadable.
 */
final class ReportExportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
    }

    private function report(array $data, string $audience = 'client'): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'Monthly',
            'type' => 'monthly',
            'status' => 'completed',
            'currency' => 'SAR',
            'audience' => $audience,
            'data' => $data,
            'generated_at' => now(),
        ]);
    }

    private function consistentData(): array
    {
        // A snapshot the gate + narrative validator accept: 1,158 results everywhere, no contradiction.
        return [
            'period' => ['from' => '2026-06-01', 'to' => '2026-06-30'],
            'currency' => 'SAR',
            'kpis' => ['spend' => 96000, 'revenue' => 796000, 'conversions' => 1158, 'roas' => 8.28, 'cpa' => 83],
            'platforms' => [['platform' => 'google', 'spend' => 96000, 'revenue' => 796000, 'conversions' => 1158, 'roas' => 8.28]],
            'campaigns' => [['name' => 'Brand', 'platform' => 'google', 'status' => 'active', 'spend' => 96000, 'conversions' => 1158]],
            'summary' => ['Delivered 1,158 attributed results at 8.28x ROAS.'],
            'recommendations' => [],
            'checksum' => 'abc123',
        ];
    }

    public function test_narrative_validator_flags_zero_results_contradiction(): void
    {
        $v = new NarrativeConsistencyValidator;
        $bad = $this->consistentData();
        $bad['summary'] = ['Strong month — delivering 0 attributed results this period.'];

        $issues = $v->scan($bad);
        $codes = array_column($issues, 'code');
        $this->assertContains('narrative_zero_results', $codes);
        // ...and a consistent snapshot passes clean.
        $this->assertSame([], $v->scan($this->consistentData()));
    }

    public function test_narrative_validator_flags_table_mismatch(): void
    {
        $v = new NarrativeConsistencyValidator;
        $bad = $this->consistentData();
        $bad['platforms'] = [['platform' => 'google', 'results' => 42]]; // ≠ kpi 1158
        $this->assertContains('results_table_mismatch', array_column($v->scan($bad), 'code'));
    }

    public function test_client_pdf_requires_chromium_and_never_falls_back_to_dompdf(): void
    {
        config()->set('reports.chromium.enabled', false);
        $report = $this->report($this->consistentData(), 'client');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Chromium renderer/i');
        app(ReportExporter::class)->render($report, 'pdf');
    }

    public function test_export_blocks_on_narrative_mismatch(): void
    {
        config()->set('reports.chromium.enabled', false);
        $bad = $this->consistentData();
        $bad['summary'] = ['We delivered 0 results.'];
        $report = $this->report($bad, 'internal'); // internal so the chromium guard isn't what trips

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('narrative/data mismatch');
        app(ReportExporter::class)->render($report, 'pdf');
    }

    public function test_stale_pdf_export_is_not_downloadable(): void
    {
        $report = $this->report($this->consistentData());
        $export = ReportExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'completed',
            'disk' => 'local',
            'path' => 'reports/x.pdf',
            'signed_token' => 'tok_'.Str::random(20),
            'expires_at' => now()->addDay(),
            'renderer' => 'dompdf',
            'renderer_version' => 'legacy',
            'template_version' => '1',
            'validation_status' => 'unknown',
        ]);

        // Legacy provenance ⇒ stale ⇒ 409 (never streamed), regardless of the file existing.
        $this->get("/api/v1/reports/download/{$export->signed_token}")->assertStatus(409);
    }

    /**
     * BRAND-ATTRIBUTION-001 — the workbook signs itself, in the only place the format allows.
     *
     * A spreadsheet has nowhere to put a mark and a banner row would break every parser that reads
     * row 1 as headers, so the product's name lives in the document properties — which a file that
     * is saved, mailed on and reopened months later still carries. Asserted by READING the written
     * file back, because a property the writer sets and the file does not keep is not attribution.
     */
    public function test_the_xlsx_carries_the_products_attribution_in_its_properties(): void
    {
        $report = $this->report($this->consistentData(), 'internal');
        $report->forceFill(['name' => 'Monthly — Acme'])->save();

        $bytes = app(ReportExporter::class)->render($report, 'xlsx');

        $file = tempnam(sys_get_temp_dir(), 'xlsxprops').'.xlsx';
        file_put_contents($file, $bytes);

        $properties = \PhpOffice\PhpSpreadsheet\IOFactory::load($file)->getProperties();

        self::assertSame('CampaignsHub', $properties->getCreator());
        self::assertSame('CampaignsHub', $properties->getCompany());
        // The REPORT is still what the file is about; the product only says it made it.
        self::assertSame('Monthly — Acme', $properties->getTitle());
        self::assertStringContainsString('campaignshub.io', $properties->getDescription());

        @unlink($file);
    }

    /**
     * And the CSV does NOT try. The format has no metadata, and a comment line would corrupt the
     * first row for anything that parses it — so the first line is headers and nothing else.
     */
    public function test_the_csv_puts_no_branding_where_a_parser_expects_headers(): void
    {
        $report = $this->report($this->consistentData(), 'internal');

        $first = strtok(app(ReportExporter::class)->render($report, 'csv'), "\n");

        self::assertStringNotContainsString('CampaignsHub', (string) $first);
        self::assertStringNotContainsString('campaignshub.io', (string) $first);
    }
}
