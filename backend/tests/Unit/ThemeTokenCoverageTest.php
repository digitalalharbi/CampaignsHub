<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A Tailwind colour utility naming a token the theme never registered applies NOTHING.
 *
 * No error, no warning, and no unit test can see it: jsdom does not resolve Tailwind, so a component
 * test asserting the class NAME passes while the rendered element has no colour at all. Three names
 * were in that state and had been for a long time:
 *
 *   - `text-text-tertiary`, in SIXTY-FOUR places including `StatCard`'s hint line — the card
 *     primitive every KPI in the product now flows through;
 *   - `bg-surface-muted`, in ten — loading skeletons, a progress track, a thumbnail placeholder, and
 *     the NEUTRAL sync-status pill, which is the one tone «no data for the period» may use
 *     (NO-DATA-NOT-RED-001). So the single state the product insists must not look alarming was also
 *     the only one rendering with no surface at all;
 *   - `bg-surface-subtle`, in three.
 *
 * Measured in a real browser rather than reasoned about: an element with `text-text-tertiary` inside
 * a parent coloured `rgb(1, 2, 3)` computed to `rgb(1, 2, 3)`. It inherited; it was doing nothing.
 *
 * They were not unwired tokens. The palette defines three text tones and three surfaces, and
 * `tertiary`, `muted` and `subtle` were never among them — so the call sites moved to the tone the
 * palette actually has, rather than three new colours being invented to justify three names.
 *
 * ## Why this guard is in PHP
 *
 * It has to read `src/index.css`, and Vite hands a `?raw` CSS import back empty — CSS is transformed
 * rather than inlined — while the frontend's `tsc -b` covers its test files with no Node types, so a
 * test there cannot read the file off disk either. PHP can read both sides. The same reasoning put
 * `RelevanceDefinitionTest` here.
 */
final class ThemeTokenCoverageTest extends TestCase
{
    private function frontend(string $relative): string
    {
        $path = __DIR__.'/../../../frontend/'.$relative;

        $this->assertFileExists($path, "{$relative} moved — this guard is watching nothing");

        return (string) file_get_contents($path);
    }

    /**
     * A BARE shadow utility renders nothing, and the theme's own comment used to recommend one.
     *
     * `shadow-small` / `shadow-medium` / `shadow-large` are registered in `@theme` as
     * `--shadow-small: var(--shadow-small)`, which is self-referential — measured in a browser, a
     * bare `shadow-small` computes to `box-shadow: none` while the token itself holds a real value.
     *
     * Nothing in the product was broken by it: all eighty-eight shadows are written
     * `shadow-[var(--shadow-small)]`, which reads the token directly. What was broken was the
     * invitation — `src/index.css` listed `shadow-medium` as an example of a utility that resolves.
     * This is what stops the next author accepting it.
     */
    public function test_no_bare_shadow_utility_is_used(): void
    {
        $offenders = [];

        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../../frontend/src')
        );

        foreach ($directory as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }

            /*
             * Only a bare token NAME is an offence.
             *
             * Both working forms read the token directly and are excluded by the lookbehind:
             * `shadow-[var(--shadow-small)]` in a class list, and `boxShadow: 'var(--shadow-medium)'`
             * in an inline style — the second is why the lookbehind is `var\(--` rather than
             * `\[var\(--`, which counted a correct inline style as a violation.
             */
            if (preg_match('/(?<!var\(--)\bshadow-(?:small|medium|large)\b/', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = str_replace(__DIR__.'/../../../frontend/', '', $file->getPathname());
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A bare `shadow-small|medium|large` computes to `box-shadow: none` — the theme registers it\n"
            ."self-referentially. Write `shadow-[var(--shadow-small)]`, which every other shadow in the\n"
            ."product uses:\n  ".implode("\n  ", $offenders),
        );
    }

    public function test_every_colour_utility_names_a_token_the_theme_registers(): void
    {
        $theme = $this->frontend('src/index.css');
        $this->assertStringContainsString('@theme', $theme);

        $missing = [];

        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__.'/../../../frontend/src')
        );

        foreach ($directory as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                continue;
            }
            // A test may name a token deliberately to prove it is refused.
            if (str_contains($file->getFilename(), '.test.')) {
                continue;
            }

            /*
             * Only the families the theme defines. Tailwind's own palette — `text-white`,
             * `bg-black/40`, `border-transparent` — is not ours to register, so the scan looks at
             * compounds naming one of our token groups and nothing else.
             */
            preg_match_all(
                '/\b(?:bg|text|border)-((?:surface|text|border)-[a-z]+)\b/',
                (string) file_get_contents($file->getPathname()),
                $found,
            );

            foreach ($found[1] ?? [] as $token) {
                if (! str_contains($theme, "--color-{$token}:")) {
                    $missing[$token] = true;
                }
            }
        }

        $names = array_keys($missing);
        sort($names);

        $this->assertSame(
            [],
            $names,
            "These colour utilities name tokens `@theme` never registered, so they apply NOTHING —\n"
            ."no error, and no frontend test can see it because jsdom does not resolve Tailwind.\n"
            ."Map the token in src/index.css, or use the tone the palette already defines:\n  "
            .implode("\n  ", $names),
        );
    }
}
