<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — one grouped read for the trend's buckets answers what eight separate reads did.
 *
 * `figuresByBlock()` is held to `forCreatives()` bucket by bucket on the same creatives: every figure,
 * every `reported` flag, every `from_ads` note, for the creative grain (Snapchat's rows), the ad grain
 * (Meta's and TikTok's) and creatives holding both. Only the `decay` and `availability` notes may be
 * absent, because the trend reads neither.
 */
final class CreativeMetricsBlocksTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
        $this->buildWorld(campaignsPerAccount: 3);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @param array<string, array<string, mixed>> $figures */
    private static function canonical(array $figures): array
    {
        ksort($figures);

        return array_map(static function (array $row): array {
            unset($row['decay'], $row['availability']);
            ksort($row);

            return $row;
        }, $figures);
    }

    /** @return list<array{0: int, 1: int}> block size and count pairs: even split, uneven split, one block, a window shorter than a block */
    public static function cuts(): array
    {
        return [[3, 7], [4, 5], [20, 1], [30, 1], [1, 20], [7, 3]];
    }

    #[DataProvider('cuts')]
    public function test_every_block_equals_a_separate_read_of_that_block(int $size, int $blocks): void
    {
        $metrics = app(CreativeMetrics::class);
        $ids = array_map('strval', DB::table('external_creatives')->where('project_id', $this->project->id)->orderBy('id')->pluck('id')->all());
        $this->assertGreaterThan(30, count($ids));

        $origin = Carbon::parse(self::WORLD_FROM);
        $to = Carbon::parse(self::WORLD_TO);

        $grouped = $metrics->figuresByBlock($ids, $origin->copy(), $size, $blocks, $to);

        $this->assertCount($blocks, $grouped);

        $grains = [];
        for ($block = 0; $block < $blocks; $block++) {
            $start = $origin->copy()->addDays($block * $size);
            $end = $start->copy()->addDays($size - 1)->min($to);

            $expected = $start->gt($to) ? [] : $metrics->forCreatives($ids, $start, $end);

            $this->assertEquals(self::canonical($expected), self::canonical($grouped[$block]), "block {$block} ({$start->toDateString()} .. {$end->toDateString()}) differs from a separate read");

            foreach ($grouped[$block] as $row) {
                $grains[(string) ($row['grain'] ?? '')] = true;
            }
        }

        $this->assertArrayHasKey('creative', $grains, 'the world has no creative-grain rows, so that path is not compared');
        $this->assertArrayHasKey('ad', $grains, 'the world has no ad-grain rows, so that path is not compared');
    }
}
