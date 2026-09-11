<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * OPS-LEDGER-001 — a bounded list must say what its bound left out.
 *
 * A dozen endpoints across this product answer with `->limit(N)->get()`. The direction is almost
 * always the safe one, newest first, so the rows a reader most likely wants are the rows they get —
 * and the ceiling used to be invisible on every one of them. A workspace with two hundred security
 * events saw fifty and had every reason to read that as its entire log.
 *
 * The failure has no symptom: 200, right shape, count under the cap the code itself asked for. That
 * is why it survived, why it was found by reading queries rather than pages, and why the fix is a
 * COUNT rather than a bigger number.
 *
 * ## Why this reads the SOURCE
 *
 * The rule is about every capped list, including ones not yet written. A per-endpoint test proves
 * today's list and says nothing about the next one, and the way this defect returns is somebody
 * adding a `limit()` in good faith. Reading the file catches that at the moment it happens.
 *
 * A file is in breach when it caps a query and never names a count. It is deliberately NOT asserted
 * which key carries it — `history_total`, `invitations_withheld` and a `meta` envelope all satisfy
 * the requirement, and forcing one spelling across nine controllers would be a refactor pretending
 * to be a guard.
 */
final class NoSilentListCapTest extends TestCase
{
    /** Controllers answer readers. A cap inside a service or a job is a different question. */
    private const ROOT = __DIR__.'/../../app/Domains';

    /**
     * Caps that bound something OTHER than a list a reader is shown, with the reason beside each.
     *
     * An allow-list rather than a looser pattern: every entry here is a claim somebody can check, and
     * a wrong one shows up as a list that quietly shortens. A pattern that skipped «small numbers»
     * would have exempted the ten-quote cap this unit fixed.
     */
    private const NOT_A_READER_LIST = [
        // Probe windows and sampling, not a list anyone reads to the end.
        'DiagnoseSyncCommand.php',
    ];

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), 'Controller.php')) {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    public function test_every_controller_that_caps_a_list_also_says_what_it_withheld(): void
    {
        $files = $this->phpFiles(self::ROOT);
        $this->assertGreaterThan(40, count($files), 'the guard found almost no controllers — it would pass having read nothing');

        $offenders = [];

        foreach ($files as $path) {
            if (in_array(basename($path), self::NOT_A_READER_LIST, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);

            /*
             * Comments stripped first. Several of these files EXPLAIN the cap and the count in prose
             * — including the ones already fixed — and a guard that read its own documentation would
             * report them as compliant on the strength of a sentence rather than a figure.
             */
            $code = preg_replace('#/\*[\s\S]*?\*/|//.*$#m', '', $source) ?? '';

            /* `limit(1)` and `take(1)` are «the newest one», not a truncated list. */
            if (preg_match('/->(limit|take)\(\s*([2-9]|[1-9]\d+)\s*\)/', $code) !== 1) {
                continue;
            }

            if (! str_contains($code, 'withheld')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these controllers cap a list and never say what the cap withheld: \n  ".implode("\n  ", $offenders),
        );
    }
}
