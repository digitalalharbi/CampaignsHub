<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the command that measures a produced export, and what it may say.
 *
 * Its output goes into a workflow log, so the assertions here are mostly about absence: no report
 * name, no stored path, no signed token. And it reads DEMO exports only — a real client's report is
 * not an acceptance fixture, and «prove the pipeline» must never mean reading somebody's figures.
 */
final class PdfFactsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Facts', 'slug' => 'facts-'.uniqid(), 'status' => 'active']);
    }

    private function requirePikepdf(): void
    {
        $probe = new Process(['python3', '-c', 'import pikepdf']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python3 + pikepdf not available on this host.');
        }
    }

    /** A structurally real PDF, built by the same library the inspector reads it with. */
    private function aRealPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'facts_').'.pdf';
        $py = <<<'PY'
import sys, pikepdf
pdf = pikepdf.new()
pdf.add_blank_page(page_size=(841.89, 595.276))
cmap = (b"/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n"
        b"1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
        b"1 beginbfchar\n<0061> <0627>\nendbfchar\nendcmap end end")
tu = pdf.make_stream(cmap)
font = pdf.make_indirect(pikepdf.Dictionary(
    Type=pikepdf.Name.Font, Subtype=pikepdf.Name.Type0,
    BaseFont=pikepdf.Name("/AAAAAA+IBMPlexSansArabic-Regular"), ToUnicode=tu))
pdf.pages[0].Resources = pikepdf.Dictionary(Font=pikepdf.Dictionary(F0=font))
pdf.pages[0].Contents = pdf.make_stream(b"0 0 1 rg 10 10 200 100 re f\n")
pdf.Root.MarkInfo = pikepdf.Dictionary(Marked=True)
pdf.save(sys.argv[1])
PY;
        $proc = new Process(['python3', '-c', $py, $path]);
        $proc->run();
        self::assertTrue($proc->isSuccessful(), $proc->getErrorOutput());

        return $path;
    }

    private function demoExport(string $bytesFrom): ReportExport
    {
        Storage::fake('exports');

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        $report = Report::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $project->getKey(),
            'name' => 'A NAME THAT MUST NOT BE LOGGED',
            'type' => 'monthly',
            'audience' => 'client',
            'status' => 'completed',
            'is_demo' => true,
        ]);

        $stored = 'reports/'.Str::uuid().'.pdf';
        Storage::disk('exports')->put($stored, (string) file_get_contents($bytesFrom));

        return ReportExport::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'report_id' => $report->id,
            'format' => 'pdf',
            'status' => 'completed',
            'disk' => 'exports',
            'path' => $stored,
            'size' => filesize($bytesFrom),
            'signed_token' => 'A-TOKEN-THAT-MUST-NOT-BE-LOGGED',
            'is_demo' => true,
            'renderer' => 'chromium',
            'renderer_version' => 'alpine-chromium-136',
            'template_version' => '2',
            'layout_mode' => 'presentation',
            'validation_status' => 'passed',
            'locale' => 'ar',
        ]);
    }

    public function test_it_measures_the_newest_demo_export(): void
    {
        $this->requirePikepdf();
        $pdf = $this->aRealPdf();

        try {
            $this->demoExport($pdf);

            Artisan::call('reports:pdf-facts');
            $out = Artisan::output();

            self::assertStringContainsString('"pages": 1', $out);
            self::assertStringContainsString('"pages_a4": 1', $out);
            self::assertStringContainsString('"tagged": true', $out);
            self::assertStringContainsString('"arabic_base_codepoints": 1', $out);
            self::assertStringContainsString('"blank_pages": 0', $out);
            // Provenance, which is what says the file came from the renderer we think it did.
            self::assertStringContainsString('chromium', $out);
            self::assertStringContainsString('passed', $out);
        } finally {
            @unlink($pdf);
        }
    }

    /** The log must carry no name, no path and no token. */
    public function test_it_prints_no_report_name_no_path_and_no_token(): void
    {
        $this->requirePikepdf();
        $pdf = $this->aRealPdf();

        try {
            $export = $this->demoExport($pdf);

            Artisan::call('reports:pdf-facts');
            $out = Artisan::output();

            self::assertStringNotContainsString('A NAME THAT MUST NOT BE LOGGED', $out);
            self::assertStringNotContainsString('A-TOKEN-THAT-MUST-NOT-BE-LOGGED', $out);
            self::assertStringNotContainsString((string) $export->path, $out);
            self::assertStringNotContainsString((string) $export->report_id, $out);
        } finally {
            @unlink($pdf);
        }
    }

    /** Nothing to measure is a refusal that says so — never a silent success. */
    public function test_it_refuses_when_there_is_no_completed_demo_export(): void
    {
        self::assertSame(1, Artisan::call('reports:pdf-facts'));
        self::assertStringContainsString('Generate one first', Artisan::output());
    }

    /**
     * The branding the report is CONFIGURED with, beside the file's measured images.
     *
     * `PrintDocument` renders a logo only when `logoUrl` is non-null, and its docblock warns that
     * «code containing `logo_url` is not the same thing as a logo rendering». Without the
     * configuration, a file with no images is indistinguishable from a report that was never given a
     * logo — and a branding check that cannot tell those apart passes vacuously.
     */
    public function test_it_states_whether_a_logo_was_configured_at_all(): void
    {
        $this->requirePikepdf();
        $pdf = $this->aRealPdf();

        try {
            $this->demoExport($pdf);

            Artisan::call('reports:pdf-facts');
            $out = Artisan::output();

            self::assertStringContainsString('configured branding', $out);
            self::assertStringContainsString('logo_source', $out);
            // And it still never names the report.
            self::assertStringNotContainsString('A NAME THAT MUST NOT BE LOGGED', $out);
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * `--allow-real` reaches a real report's export, because a production box holds no demo ones —
     * and it still prints nothing that could reconstruct a client's figures.
     */
    public function test_allow_real_reaches_a_real_export_without_printing_its_content(): void
    {
        $this->requirePikepdf();
        $pdf = $this->aRealPdf();

        try {
            $export = $this->demoExport($pdf);
            $export->forceFill(['is_demo' => false])->save();

            // Default still refuses, and says how to proceed.
            self::assertSame(1, Artisan::call('reports:pdf-facts'));
            self::assertStringContainsString('--allow-real', Artisan::output());

            self::assertSame(0, Artisan::call('reports:pdf-facts', ['--allow-real' => true]));
            $out = Artisan::output();

            self::assertStringContainsString('"pages": 1', $out);
            self::assertStringContainsString('real report, structural facts only', $out);
            self::assertStringNotContainsString('A NAME THAT MUST NOT BE LOGGED', $out);
            self::assertStringNotContainsString('A-TOKEN-THAT-MUST-NOT-BE-LOGGED', $out);
            self::assertStringNotContainsString((string) $export->path, $out);
        } finally {
            @unlink($pdf);
        }
    }

    /**
     * A real client's export is not an acceptance fixture, and the filter is the only thing keeping
     * this command out of one.
     */
    public function test_it_ignores_an_export_that_is_not_demo(): void
    {
        $this->requirePikepdf();
        $pdf = $this->aRealPdf();

        try {
            $export = $this->demoExport($pdf);
            $export->forceFill(['is_demo' => false])->save();

            self::assertSame(1, Artisan::call('reports:pdf-facts'));
            self::assertStringContainsString('Generate one first', Artisan::output());
        } finally {
            @unlink($pdf);
        }
    }
}
