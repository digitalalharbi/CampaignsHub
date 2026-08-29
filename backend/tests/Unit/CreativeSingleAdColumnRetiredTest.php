<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CREATIVE-COLUMN-RETIRE-001 — nothing reaches for `external_creatives.external_ad_id` again.
 *
 * The column looked like a relation and was not: the importer rewrote it on every upsert, so it held
 * whichever ad was imported last, and on the live Snapchat account four ads share each creative.
 * Everything built on it was true of one arbitrary quarter while reading as definite.
 *
 * It is dropped now, so a reference would be a runtime error rather than a wrong answer — but the
 * error would surface on a customer's screen, and the name is short enough to be retyped by anyone
 * who remembers it. This is the cheaper place to find out.
 *
 * `leads.external_ad_id` is a DIFFERENT column and is legitimate: a lead genuinely arrives from one
 * ad, and the provider says which. The check is scoped to the creative domain rather than to the
 * name, because banning the name outright would ban a fact.
 */
final class CreativeSingleAdColumnRetiredTest extends TestCase
{
    /** Where the retired column would plausibly come back. */
    private const SCOPES = [
        'app/Domains/Campaigns',
        'app/Domains/Content',
        'app/Domains/Reports',
        'database/seeders',
    ];

    public function test_no_creative_code_reads_or_writes_the_retired_column(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (self::SCOPES as $scope) {
            $path = $root.'/'.$scope;
            if (! is_dir($path)) {
                continue;
            }

            /*
             * Plain SPL rather than the `File` facade: this is a `PHPUnit\Framework\TestCase`, with
             * no application booted, and reaching for a facade here fails with «a facade root has not
             * been set». The test reads files; it has no business needing a container.
             */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $n => $line) {
                    if (! str_contains($line, 'external_ad_id')) {
                        continue;
                    }

                    // A comment explaining why it is gone is the point of the exercise, not a breach.
                    $trimmed = ltrim($line);
                    if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                        continue;
                    }

                    $offenders[] = str_replace($root.'/', '', $file->getPathname()).':'.($n + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "`external_creatives.external_ad_id` is retired — use the `external_ads.creative_id` relation:\n  "
            .implode("\n  ", $offenders),
        );
    }

    /** The migration that removes it exists, and puts it back on the way down. */
    public function test_the_column_is_dropped_by_a_reversible_migration(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_08_30_090000_retire_the_creatives_single_ad_column.php';

        $this->assertFileExists($path);

        $source = file_get_contents($path);
        $this->assertStringContainsString("dropColumn('external_ad_id')", $source);
        $this->assertStringContainsString("string('external_ad_id')", $source, 'down() does not restore the column');
    }
}
