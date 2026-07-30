<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The request form offers goal-first bundles (frontend/src/features/requests/serviceBundles.ts) that
 * pre-select real catalogue services. This test guards the seam between the two: a mistyped key would
 * select NOTHING and let a client submit an empty request believing they had chosen.
 *
 * It reads both files as text rather than booting the UI, so it fails the moment either side drifts.
 */
final class ServiceBundleCatalogTest extends TestCase
{
    public function test_every_bundle_service_exists_in_the_seeded_catalogue(): void
    {
        $bundles = base_path('../frontend/src/features/requests/serviceBundles.ts');
        $seeder = database_path('seeders/TaxonomyEngineSeeder.php');

        $this->assertFileExists($bundles);
        $this->assertFileExists($seeder);

        preg_match_all("/'key' => '([a-z_]+)'/", (string) file_get_contents($seeder), $catalogue);
        $known = array_flip($catalogue[1]);

        preg_match_all('/services: \[([^\]]+)\]/', (string) file_get_contents($bundles), $lists);
        $this->assertNotEmpty($lists[1], 'no bundles found — the file moved or its shape changed');

        $referenced = [];
        foreach ($lists[1] as $list) {
            preg_match_all("/'([a-z_]+)'/", $list, $keys);
            $referenced = array_merge($referenced, $keys[1]);
        }

        $this->assertGreaterThanOrEqual(18, count($referenced), 'expected every bundle to name its services');

        foreach (array_unique($referenced) as $key) {
            $this->assertArrayHasKey($key, $known, "bundle service '{$key}' does not exist in the catalogue");
        }
    }
}
