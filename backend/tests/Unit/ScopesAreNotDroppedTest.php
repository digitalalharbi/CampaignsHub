<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CAMPAIGNS-LEDGER-001 — the mechanism that drops a bound, guarded once rather than per surface.
 *
 * `Builder::getQuery()` returns the underlying query builder WITHOUT applying scopes; `toBase()` is
 * `applyScopes()->getQuery()` and is the one that keeps them. One character of difference decides
 * whether a grouped count obeys tenancy and project isolation, and it went the wrong way in three
 * controllers:
 *
 *   - `UnifiedCampaignController` reported «20 active» for a project holding three campaigns.
 *   - `SyncRunController` counted other projects' and other TENANTS' runs into the summary an
 *     operator reads to decide whether their own figures can be trusted.
 *   - `TaskController`'s project-scoped route counted other projects' tasks beside this project's
 *     rows — the rows right and the counts wrong, which is why nobody saw it.
 *
 * ## Why this is a source check and not three more feature tests
 *
 * Each of those has one now, and each proves its own surface. None of them would have caught the
 * fourth. An earlier note on `ANALYTICS-FILTER-TRUTH-001` records a structural sweep that «found no
 * third instance» — true of the shape it swept for, a filter applied after pagination, and blind to
 * the same lie told through a different call. So this guards the CALL, which is the thing that
 * cannot be told apart by reading the surrounding line.
 *
 * If a legitimate use ever arrives, it goes in `ALLOWED` with a reason. An exemption nobody can read
 * is how a guard stops guarding.
 */
final class ScopesAreNotDroppedTest extends TestCase
{
    /** @var array<string, string> */
    private const ALLOWED = [];

    public function test_no_application_code_unwraps_a_builder_past_its_scopes(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(__DIR__.'/../../app') as $path => $source) {
            if (array_key_exists($path, self::ALLOWED)) {
                continue;
            }

            // Comments explain the rule; only code breaks it.
            $code = preg_replace('#/\*[\s\S]*?\*/|//.*$#m', '', $source) ?? $source;

            if (str_contains($code, '->getQuery()')) {
                $offenders[] = "{$path}: `->getQuery()` drops every global scope — use `toBase()` if the base builder is what is wanted";
            }
        }

        $this->assertSame([], $offenders, implode("\n  ", $offenders));
    }

    /** The vacuity check: a guard that reads no files passes loudest. */
    public function test_it_reads_the_application_tree(): void
    {
        $this->assertGreaterThan(200, count($this->phpFiles(__DIR__.'/../../app')), 'the sweep found almost no source');
    }

    /** @return array<string, string> */
    private function phpFiles(string $root): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[str_replace(realpath($root).'/', '', (string) $file->getRealPath())] = (string) file_get_contents((string) $file->getRealPath());
            }
        }

        return $out;
    }
}
