<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Contract for the production PDF fact inspector — REPORT-EXPORT-FUNCTIONAL-001.
 *
 * The inspector decides whether a real production PDF met the acceptance bar, so a measurement bug in
 * it is indistinguishable from a product defect. It has already had three, every one found by running
 * it on a real Chromium file rather than by reading it: it counted image XObjects and called a deck of
 * vector charts blank; it ran one pair-regex over both CMap shapes, so digits emitted as a `bfrange`
 * vanished and it reported a report with no numbers in it; and it scanned raw bytes for identifiers,
 * which compression hides, so it answered «I cannot see one» as «there is not one».
 *
 * Runs the self-contained python contract suite (synthetic fixtures, no Chromium) so `php artisan
 * test` covers all three. Skipped only if python3/pikepdf are absent — the production image carries
 * both, which is why the inspector is written against pikepdf alone.
 */
final class PdfProductionFactsTest extends TestCase
{
    public function test_pdf_production_facts_contract(): void
    {
        $probe = new Process(['python3', '-c', 'import pikepdf']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python3 + pikepdf not available on this host.');
        }

        $test = base_path('scripts/tests/test_pdf_production_facts.py');
        $proc = new Process(['python3', $test], base_path());
        $proc->run();

        $this->assertTrue(
            $proc->isSuccessful(),
            'PDF production-facts contract failed: '.$proc->getOutput().$proc->getErrorOutput(),
        );
        $this->assertStringContainsString('all pdf production-facts contract tests passed', $proc->getOutput());
    }
}
