<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Providers\ReportsEntityGrains;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * OPENAI-ADS-INSIGHTS-001 — a connector that answers the entity grains speaks the contract's key.
 *
 * `ReportsEntityGrains` says a row carries «the provider's own entity id in `entity_id`», and
 * `UpsertEntityDailyMetrics` reads exactly that key. The ChatGPT Ads connector wrote
 * `entity_external_id` instead: every one of its ad-group and ad rows resolved to no entity, and the
 * run reported «none matched a discovered ad-set» on an account whose ad groups had been discovered
 * perfectly. A per-connector feature test proves one connector; this holds the KEY itself, across
 * every implementor, so the next connector cannot ship the same row under its own spelling.
 */
final class EntityGrainContractSourceGuardTest extends TestCase
{
    public function test_every_grain_connector_names_its_rows_by_the_contracts_key(): void
    {
        $implementors = [];

        foreach (File::allFiles(app_path('Domains/Integrations/Providers')) as $file) {
            $source = $file->getContents();

            if (preg_match('/^final class \w+ extends \w+ implements [^{]*\bReportsEntityGrains\b/m', $source) !== 1) {
                continue;
            }

            $implementors[] = $file->getFilename();

            $this->assertStringContainsString(
                "'entity_id'",
                $source,
                "{$file->getFilename()} answers the entity grains and never names a row by `entity_id`, the key the upsert reads",
            );
            $this->assertStringNotContainsString(
                'entity_external_id',
                $source,
                "{$file->getFilename()} names a grain row by `entity_external_id` — the key nothing reads; the contract is `entity_id`",
            );
        }

        // The guard must have walked somebody, or a renamed interface would make it pass over nothing.
        $this->assertGreaterThanOrEqual(4, count($implementors), 'fewer grain connectors than the product is known to hold: '.implode(', ', $implementors));
        $this->assertStringContainsString('`entity_id`', (string) file_get_contents((new \ReflectionClass(ReportsEntityGrains::class))->getFileName()), 'the contract no longer names its own key');
    }
}
