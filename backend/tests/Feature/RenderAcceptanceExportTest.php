<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Console\RenderAcceptanceExportCommand;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * REPORT-EXPORT-FUNCTIONAL-001 — the command that gives production a file to judge.
 *
 * Production's census settled why this is needed, in counts only: fourteen real reports, five of them
 * renderable, ZERO demo reports and zero exports ever. `reports:regenerate-demo-exports` is right to do
 * nothing there, so the bar — a real production PDF that opens — cannot be met by the demo path.
 *
 * What is tested here is the command's own judgement, not the renderer: that it refuses without the
 * flag, that it renders exactly one, and that its output carries no report name, no stored path and no
 * signed token. The rendering itself is the export chain every other test already covers.
 */
final class RenderAcceptanceExportTest extends TestCase
{
    use RefreshDatabase;

    private function aRenderableReport(string $name): Report
    {
        $tenant = Tenant::create(['name' => 'Acc', 'slug' => 'acc-'.uniqid(), 'status' => 'active']);
        $client = ClientWorkspace::create([
            'tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);

        return Report::create([
            'tenant_id' => $tenant->getKey(),
            'project_id' => $project->getKey(),
            'name' => $name,
            'type' => 'monthly',
            'audience' => 'client',
            'status' => 'completed',
            'data' => ['totals' => ['spend' => 1]],
            'is_demo' => false,
        ]);
    }

    /** Without the flag it states what it would do and changes nothing. */
    public function test_it_refuses_without_the_explicit_flag(): void
    {
        $this->aRenderableReport('A NAME THAT MUST NOT BE LOGGED');

        self::assertSame(1, Artisan::call('reports:render-acceptance'));
        self::assertStringContainsString('--allow-real', Artisan::output());
        self::assertSame(0, ReportExport::withoutGlobalScopes()->count(), 'a refusal must not leave an export row behind');
    }

    /** Nothing renderable is said plainly, never a silent success. */
    public function test_it_says_so_when_there_is_nothing_renderable(): void
    {
        self::assertSame(1, Artisan::call('reports:render-acceptance', ['--allow-real' => true]));
        self::assertStringContainsString('nothing to render', Artisan::output());
    }

    /**
     * Exactly ONE row, even with several renderable reports and even when the render fails.
     *
     * `ReportExporter` is final and is not mocked — a test that needed the class opened up would be
     * testing a shape invented for it. With the renderer switched off, which is the default outside
     * production, the real chain refuses and the command's own bound is still what is under test: one
     * report chosen, one row written, whatever the renderer then says.
     */
    public function test_it_creates_exactly_one_export_row(): void
    {
        config()->set('reports.chromium.enabled', false);
        $this->aRenderableReport('FIRST');
        $this->aRenderableReport('SECOND');
        $this->aRenderableReport('THIRD');

        Artisan::call('reports:render-acceptance', ['--allow-real' => true]);

        // `withoutGlobalScopes`: an export is tenant-scoped, and this test holds no tenant context —
        // counting through the scope reports 0 for a row that exists, which reads as «it created
        // nothing» when the truth is «I cannot see it».
        self::assertSame(1, ReportExport::withoutGlobalScopes()->count());
    }

    /**
     * A failure is named in the product's vocabulary, not the renderer's stderr.
     *
     * `ExportFailureReason` exists so an operator reads «the renderer is not enabled on this server»
     * rather than a stack trace, and this is exactly the case it was written for. The row keeps the raw
     * message; the log gets the word.
     */
    public function test_a_failed_render_is_classified_rather_than_dumped(): void
    {
        config()->set('reports.chromium.enabled', false);
        $this->aRenderableReport('A NAME THAT MUST NOT BE LOGGED');

        self::assertSame(1, Artisan::call('reports:render-acceptance', ['--allow-real' => true]));
        $out = Artisan::output();

        self::assertStringContainsString('renderer_disabled', $out);
        self::assertStringNotContainsString('Stack trace', $out);
        self::assertStringNotContainsString('A NAME THAT MUST NOT BE LOGGED', $out);
        self::assertSame('failed', (string) ReportExport::withoutGlobalScopes()->first()?->status);
    }

    /** The output belongs in a workflow log, on either path. */
    public function test_it_prints_no_name_no_path_and_no_token(): void
    {
        config()->set('reports.chromium.enabled', false);
        $report = $this->aRenderableReport('A NAME THAT MUST NOT BE LOGGED');

        Artisan::call('reports:render-acceptance', ['--allow-real' => true]);
        $out = Artisan::output();

        self::assertStringNotContainsString('A NAME THAT MUST NOT BE LOGGED', $out);
        self::assertStringNotContainsString((string) $report->id, $out);
        self::assertStringNotContainsString('reports/', $out, 'a stored path reached the log');
    }

    /** The dry statement says whether the report it would touch is a real one. */
    public function test_the_dry_statement_says_whether_the_report_is_real(): void
    {
        $this->aRenderableReport('REAL');

        Artisan::call('reports:render-acceptance');

        self::assertStringContainsString('is_demo=no', Artisan::output());
    }

    /** The command is registered, or the workflow calls something that does not exist. */
    public function test_the_command_is_registered(): void
    {
        self::assertArrayHasKey('reports:render-acceptance', Artisan::all());
        self::assertInstanceOf(RenderAcceptanceExportCommand::class, Artisan::all()['reports:render-acceptance']);
    }
}
