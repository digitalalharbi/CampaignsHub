<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * INTEGRATION-META-001 — a probe that can write is not a probe.
 *
 * `integrations:meta-probe --sync` runs a real discovery and writes metric rows. The workflow that
 * exposes the probe to production deliberately does not pass that flag, and this is the guard on
 * that intent: the distance between «ask Meta what this token can do» and «start a real sync on
 * production from a button» is five characters, and a comment saying so protects nothing.
 *
 * The same rule holds for the diagnosis workflow beside it, whose own header states it can only ask
 * questions.
 */
final class ProbeWorkflowsStayReadOnlyTest extends TestCase
{
    private const READ_ONLY_WORKFLOWS = [
        'meta-access-probe.yml',
        'production-diagnostics.yml',
    ];

    public function test_a_read_only_workflow_never_runs_a_writing_command(): void
    {
        foreach (self::READ_ONLY_WORKFLOWS as $file) {
            $path = dirname(__DIR__, 3).'/.github/workflows/'.$file;
            $this->assertFileExists($path, "$file is missing — it is the only way to ask production this question.");

            foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
                // Comments explain WHY the flag is refused; only executable lines are held to it.
                if (preg_match('/^\s*#/', $line) === 1) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    '--sync',
                    $line,
                    "$file:".($number + 1).' passes --sync, which writes. A workflow described as read-only must stay read-only.',
                );
            }
        }
    }
}
