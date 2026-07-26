<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Contract for the fail-closed Arabic text-layer normaliser. Runs the self-contained python
 * contract suite (synthetic fixtures — no Chromium) so `php artisan test` covers: folding
 * presentation forms to base letters, preserving ASCII/digits, byte-idempotency, and
 * fail-closed rejection when forms cannot be resolved. Skipped only if python3/pikepdf absent.
 */
final class ArabicTextLayerTest extends TestCase
{
    public function test_arabic_text_layer_normaliser_contract(): void
    {
        $probe = new Process(['python3', '-c', 'import pikepdf']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('python3 + pikepdf not available on this host.');
        }

        $test = base_path('scripts/tests/test_arabic_textlayer.py');
        $proc = new Process(['python3', $test], base_path());
        $proc->run();

        $this->assertTrue(
            $proc->isSuccessful(),
            'Arabic text-layer contract failed: '.$proc->getOutput().$proc->getErrorOutput(),
        );
        $this->assertStringContainsString('all arabic text-layer contract tests passed', $proc->getOutput());
    }
}
