<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Access\Support\AccessCatalogue;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * ACCESS-CENTER-001 — every permission key the code checks is a key the access center can show and switch.
 *
 * A guard that checks a key no area holds is a route the owner can never open or close from «الفريق والنطاقات»:
 * it would silently follow whatever the role happened to carry. This scans `app/` and `routes/` for the literal keys
 * the code asks about — `hasPermission('…')`, `->can('…')`-style checks on permissions, the `permission:` middleware,
 * and the tenant-permission map `ProjectAbilities::FROM_TENANT` — and requires each to be in an area, in the base,
 * or in the client scope. A key that is genuinely not the owner's to switch goes in ALLOWLIST with a reason.
 */
final class AccessCatalogueSourceGuardTest extends TestCase
{
    /** Keys checked in code that the access center deliberately does not switch. */
    private const ALLOWLIST = [];

    public function test_every_checked_permission_key_maps_to_the_access_catalogue(): void
    {
        $root = dirname(__DIR__, 2);
        $known = [...AccessCatalogue::switchableKeys(), ...AccessCatalogue::BASE, ...AccessCatalogue::SCOPE, ...self::ALLOWLIST];
        $found = [];

        foreach (['app', 'routes'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                preg_match_all("/hasPermission\\(\\s*'([a-z_]+\\.[a-z_.]+)'/", $src, $m1);
                preg_match_all('/permission:([a-z_]+\\.[a-z_.]+)/', $src, $m2);
                foreach ([...$m1[1], ...$m2[1]] as $key) {
                    $found[$key][] = str_replace($root.'/', '', $file->getPathname());
                }
            }
        }

        $abilities = (string) file_get_contents($root.'/app/Domains/Projects/Access/ProjectAbilities.php');
        if (preg_match('/FROM_TENANT = \[(.*?)\n    \];/s', $abilities, $block) === 1) {
            preg_match_all("/'([a-z_]+\\.[a-z_.]+)'\\s*=>/", $block[1], $m3);
            foreach ($m3[1] as $key) {
                $found[$key][] = 'app/Domains/Projects/Access/ProjectAbilities.php (FROM_TENANT)';
            }
        }

        $this->assertNotEmpty($found, 'the scan found no permission checks at all — the pattern is stale');

        $unmapped = [];
        foreach ($found as $key => $files) {
            if (! in_array($key, $known, true)) {
                $unmapped[$key] = array_values(array_unique($files));
            }
        }

        $this->assertSame([], $unmapped, 'permission keys checked in code but in no access-center area: '.json_encode($unmapped, JSON_PRETTY_PRINT));
    }
}
