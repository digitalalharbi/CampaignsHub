<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route NAME this codebase writes down is a route that exists.
 *
 * ## Why a sweep and not two more assertions
 *
 * This has now cost two separate repairs, the same way both times: the api routes are registered
 * inside a group that prefixes every name with `api.v1.`, and a caller wrote the bare name.
 *
 *   - `reports.download` was reported by a diagnostic as «the route could not be reached», which
 *     reads as a broken route rather than a misspelled reference;
 *   - `branding.assets.file` made `BrandingCenterController::present()` throw
 *     `RouteNotFoundException`, which Laravel renders as a 500 — and because `present()` is on the
 *     way OUT of both `upload()` and `assets()`, the entire Branding Center answered 500. An agency
 *     could not upload a logo, or list the ones it had.
 *
 * Neither had a test, and the second could not have been caught by one that mattered: every
 * branding suite in this tree calls the SERVICE directly and never goes through the controller.
 * What fails here is a reference being WRITTEN, and absence is only visible against a list.
 *
 * ## What it reads, and what it deliberately does not
 *
 * Literal names only — `route('a.b')`, `->route('a.b')`, `redirect()->route('a.b')`. A name built
 * from a variable or a match arm cannot be resolved by reading the source, and guessing at one would
 * make this guard fail on code that is fine, which is the fastest way to have it deleted.
 */
final class RouteNamesInCodeResolveTest extends TestCase
{
    public function test_every_literal_route_name_in_the_code_names_a_registered_route(): void
    {
        $registered = collect(Route::getRoutes()->getRoutesByName())->keys()->all();
        $this->assertNotEmpty($registered, 'no routes were registered, so this guard would pass vacuously');

        $bad = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = (string) file_get_contents($file);

            /*
             * The bare HELPER only, never `->route(...)`.
             *
             * `$request->route('project')` reads a route PARAMETER and has nothing to do with names;
             * a first cut matched it and reported nine healthy files, which is how a guard gets
             * deleted rather than fixed. `redirect()->route()` is given up with it — both real
             * defects were the bare helper, and a guard that is right about less is worth more than
             * one nobody trusts.
             */
            if (preg_match_all("/(?<![>\\$\\w:])route\\(\\s*'([a-zA-Z0-9_.\\-]+)'/", $source, $m) === 0) {
                continue;
            }

            foreach ($m[1] as $name) {
                // A path is not a name; `route()` is also called with url-ish literals in a few places.
                if (str_contains($name, '/') || in_array($name, $registered, true)) {
                    continue;
                }

                $bad[] = str_replace(base_path().'/', '', $file).": route('{$name}')";
            }
        }

        $this->assertSame([], $bad, "these names resolve to no registered route:\n".implode("\n", $bad));
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
