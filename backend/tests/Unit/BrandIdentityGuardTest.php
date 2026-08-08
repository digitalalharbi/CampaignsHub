<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * BRAND-GUARD-001 — the old identity cannot come back.
 *
 * The product is «CampaignsHub — كل حملاتك الإعلانية المدفوعة في مكان واحد» and nothing else. It
 * used to be called MediaBuying, and the rename was done by hand: every surface was fixed once,
 * nothing enforced it afterwards, and the old name still turned up months later in a health endpoint
 * that every monitor and status page quotes back (`BRAND-001`).
 *
 * A rename that is a habit decays. This makes it a property of the build.
 *
 * ## Why a scanner rather than a lint rule
 *
 * The strings are not confined to one language or one layer: they appear in Blade, PHP, TypeScript,
 * JSON and HTML, in copy, in config defaults and in serialised payloads. One scanner over the
 * shipped directories catches all of it, and it fails with the FILE AND LINE so the fix is obvious
 * rather than a hunt.
 *
 * ## What is deliberately allowed
 *
 * The database names (`mediabuying`, `mediabuying_test`, `mediabuying_e2e`) are infrastructure, not
 * identity: renaming a developer's local database would break every checkout to change a string no
 * customer will ever read. And a comment explaining what the old name WAS — including this one — has
 * to be sayable, or the guard could never be documented.
 */
final class BrandIdentityGuardTest extends TestCase
{
    /**
     * Identities this product must never present again.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        'mediabuying-api' => 'the old service name, which a health endpoint published for months',
        'MediaBuying' => 'the old product name',
        'ميديا باينج' => 'the old positioning, transliterated — a visitor should not need the English term',
        'أدر كل عميل ومشروع وحملة من مكان واحد' => 'a superseded tagline',
        'Run every client, project, and campaign from one place' => 'a superseded tagline',
    ];

    /** Where shipped copy lives. Tests and docs are excluded: they must be able to name the past. */
    private const ROOTS = [
        'backend/app',
        'backend/config',
        'backend/resources',
        'frontend/src',
    ];

    private const EXTENSIONS = ['php', 'ts', 'tsx', 'blade', 'html', 'json'];

    /** @return list<array{file: string, line: int, needle: string, why: string}> */
    private function offences(): array
    {
        $repo = dirname(__DIR__, 3);
        $out = [];

        foreach (self::ROOTS as $root) {
            $path = $repo.'/'.$root;
            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }

                $relative = str_replace($repo.'/', '', $file->getPathname());

                // A test may name the old identity; that is how this guard describes itself.
                if (str_contains($relative, '/tests/') || str_ends_with($relative, '.test.ts') || str_ends_with($relative, '.test.tsx')) {
                    continue;
                }

                foreach (file($file->getPathname()) ?: [] as $index => $line) {
                    foreach (self::FORBIDDEN as $needle => $why) {
                        if (! str_contains($line, $needle)) {
                            continue;
                        }

                        // The database names are infrastructure, and a comment must be able to
                        // explain what the old name was — otherwise this guard is undocumentable.
                        if (preg_match('/mediabuying(_test|_e2e)?\b(?!-api)/i', $line) && ! str_contains($line, 'mediabuying-api')) {
                            continue;
                        }
                        if (preg_match('#^\s*(//|\*|/\*|\{\{--|#)#', $line)) {
                            continue;
                        }

                        $out[] = ['file' => $relative, 'line' => $index + 1, 'needle' => $needle, 'why' => $why];
                    }
                }
            }
        }

        return $out;
    }

    public function test_no_shipped_surface_carries_a_superseded_identity(): void
    {
        $offences = $this->offences();

        $message = implode("\n", array_map(
            static fn (array $o): string => "  {$o['file']}:{$o['line']} — «{$o['needle']}» ({$o['why']})",
            $offences,
        ));

        $this->assertSame([], $offences, "A superseded identity is shipping:\n".$message);
    }

    /** The one identity, in the one place every surface reads it from. */
    public function test_the_official_identity_is_what_the_config_holds(): void
    {
        $brand = require dirname(__DIR__, 2).'/config/brand.php';

        $this->assertSame('CampaignsHub', $brand['name']);
        $this->assertSame('كل حملاتك الإعلانية المدفوعة في مكان واحد', $brand['tagline']['ar']);
        $this->assertSame('All your paid campaigns in one place', $brand['tagline']['en']);
        $this->assertSame('info@campaignshub.io', $brand['support_email']);
        $this->assertSame('campaignshub.io', $brand['domain']);
    }
}
