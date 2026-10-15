<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * STORAGE-PERSIST-001 — what the product writes to its own disk survives a deploy.
 *
 * Brand logos, uploaded files, generated PDFs and the share-card cache live in `storage/app`. The
 * production compose file mounted a volume for the database and for nothing else, and a deploy
 * recreates the container: every logo a client uploaded was deleted by the next release, and a
 * shared-link card quietly lost the mark it had been drawn with. Found when the HENKA card, drawn
 * with the client's logo hours earlier, came back without it after three unrelated deploys.
 */
final class ProductionStorageIsPersistentTest extends TestCase
{
    private const TARGET = '/var/www/html/storage/app';

    /** @return array<string, mixed> */
    private function compose(): array
    {
        $path = dirname(__DIR__, 3).'/deploy/docker-compose.production.yml';
        $this->assertFileExists($path);

        return Yaml::parseFile($path);
    }

    public function test_every_service_that_runs_the_application_mounts_the_same_storage_volume(): void
    {
        $services = $this->compose()['services'];

        foreach (['backend', 'queue', 'scheduler'] as $name) {
            $mounts = array_map('strval', $services[$name]['volumes'] ?? []);

            $this->assertContains(
                'campaignshub_storage:'.self::TARGET,
                $mounts,
                "{$name} writes to storage/app on its own disk, so a deploy deletes what it wrote",
            );
        }
    }

    public function test_the_storage_volume_is_declared(): void
    {
        $this->assertArrayHasKey('campaignshub_storage', $this->compose()['volumes']);
    }

    public function test_the_disk_the_product_writes_to_is_the_mounted_one(): void
    {
        $filesystems = (string) file_get_contents(dirname(__DIR__, 2).'/config/filesystems.php');

        $this->assertStringContainsString("storage_path('app/private')", $filesystems);
        $this->assertStringContainsString("storage_path('app/public')", $filesystems);
    }
}
