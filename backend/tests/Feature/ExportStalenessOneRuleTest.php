<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Support\ExportStaleness;
use Tests\TestCase;

/**
 * REPORT-EXPORT-STALE-DEADEND-001 — the endpoint that refuses and the list that offers ask ONE rule.
 *
 * ## Why they have to
 *
 * `ReportDownloadController` refuses a stale export with 409, correctly: a file produced by a
 * different renderer or template is not the document the current pipeline would produce. The reports
 * LIST drew the same export as a plain `<a href>`, because the rule was a private method on the
 * controller and nothing else could ask it.
 *
 * The result was a chip that looks ready, one click, and a browser navigating to a JSON error body —
 * reported as «PDF export does not work» while the renderer had done its job.
 *
 * ## Not an edge case
 *
 * `renderer_version` is CONFIGURED. Any deploy that changes it turns every export made before it
 * into a dead link at once, for every customer, with no failed row anywhere to explain why.
 *
 * ## What is pinned
 *
 * The three reasons, and — the point of the unit — that a current export is NOT stale, so the fix
 * cannot be «call everything stale», which would close the defect by removing the feature.
 */
final class ExportStalenessOneRuleTest extends TestCase
{
    /** A file the current pipeline produced is handed over. */
    public function test_a_current_pdf_is_not_stale(): void
    {
        $this->assertNull(ExportStaleness::reason($this->export()));
    }

    /** The Arabic text layer is a fact about the FILE, whatever produced it. */
    public function test_a_pdf_whose_text_layer_never_passed_is_stale(): void
    {
        $this->assertSame(
            ExportStaleness::VALIDATION_FAILED,
            ExportStaleness::reason($this->export(['validation_status' => 'unknown'])),
        );
    }

    /**
     * **The one a deploy causes.** The renderer moved on; every earlier file is now a dead link.
     */
    public function test_a_pdf_from_an_earlier_renderer_is_stale(): void
    {
        $this->assertSame(
            ExportStaleness::RENDERER_CHANGED,
            ExportStaleness::reason($this->export(['renderer_version' => 'chromium-1130'])),
        );
    }

    public function test_a_pdf_from_an_earlier_template_is_stale(): void
    {
        $this->assertSame(
            ExportStaleness::TEMPLATE_CHANGED,
            ExportStaleness::reason($this->export(['template_version' => 'v0-ancient'])),
        );
    }

    /**
     * A CSV carries no renderer, no template and no text layer, so none of the three can apply.
     *
     * Marking one stale would take a working download away to fix a problem it never had.
     */
    public function test_a_tabular_export_is_never_stale(): void
    {
        $this->assertNull(ExportStaleness::reason($this->export([
            'format' => 'csv',
            'validation_status' => 'unknown',
            'renderer_version' => 'whatever',
            'template_version' => 'whatever',
        ])));
    }

    /** @param array<string,mixed> $over */
    private function export(array $over = []): ReportExport
    {
        $export = new ReportExport;
        $export->forceFill([
            'format' => 'pdf',
            'status' => 'completed',
            'validation_status' => 'passed',
            'renderer' => 'chromium',
            'renderer_version' => (string) config('reports.chromium.renderer_version', 'chromium-1228'),
            'template_version' => ReportExporter::TEMPLATE_VERSION,
            ...$over,
        ]);

        return $export;
    }
}
