<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Services\CreativeFunnel;
use PHPUnit\Framework\TestCase;

/**
 * CONTENT-VIDEO-RETENTION-001 — where people stop watching, which every row already knew.
 *
 * `video_p25`, `video_p50`, `video_p75` and `video_p100` are written on every metric row, carry
 * labels in the catalogue, and are drawn nowhere. For a video creative «where do people stop
 * watching» is the question the whole asset is judged on, and the answer was four numbers in a table.
 *
 * ## It is a real funnel, which the pipeline funnel's own stages are not
 *
 * The quartiles NEST: everybody who reached 50% reached 25% first, so a tapering shape is a true
 * claim about this data — unlike a snapshot of who is standing at which stage, where the shape would
 * assert a flow nobody measured. That difference is why this gets a funnel and the leads pipeline
 * does not.
 *
 * ## An unreported quartile is not a cliff
 *
 * A platform that reports completions and no quartiles leaves three holes. Filling them with zero
 * would draw a video everybody abandoned at the first quarter and then finished anyway, which is
 * both impossible and the exact coercion `FUNNEL-NULL-001` exists to prevent. The step is absent and
 * the chart is told so.
 */
final class CreativeVideoRetentionTest extends TestCase
{
    public function test_it_builds_the_watch_through_from_views_to_completion(): void
    {
        $stages = (new CreativeFunnel)->video($this->metrics([
            'video_views' => 1_000, 'video_p25' => 800, 'video_p50' => 600, 'video_p75' => 400, 'video_p100' => 300,
        ]))['stages'];

        $this->assertSame(
            ['video_views', 'video_p25', 'video_p50', 'video_p75', 'video_p100'],
            array_column($stages, 'key'),
        );
        $this->assertSame([1_000, 800, 600, 400, 300], array_column($stages, 'count'));
    }

    public function test_each_step_carries_its_share_of_the_one_before_it(): void
    {
        $stages = (new CreativeFunnel)->video($this->metrics([
            'video_views' => 1_000, 'video_p25' => 500, 'video_p50' => 250, 'video_p75' => 200, 'video_p100' => 100,
        ]))['stages'];

        $this->assertNull($stages[0]['rate_from_previous']);
        $this->assertSame(0.5, $stages[1]['rate_from_previous']);
        $this->assertSame(0.5, $stages[2]['rate_from_previous']);
    }

    /** An unreported quartile is a hole, never a zero — see FUNNEL-NULL-001. */
    public function test_a_quartile_the_platform_never_sent_is_absent_rather_than_zero(): void
    {
        $result = (new CreativeFunnel)->video($this->metrics([
            'video_views' => 1_000, 'video_p100' => 300,
        ], reported: ['video_views' => true, 'video_p100' => true]));

        $this->assertSame(['video_views', 'video_p100'], array_column($result['stages'], 'key'));
        $this->assertSame(['video_p25', 'video_p50', 'video_p75'], array_column($result['missing'], 'key'));
    }

    /** A reported ZERO is a measurement and stays on the chart — nobody watched that far. */
    public function test_a_reported_zero_is_a_step_rather_than_a_hole(): void
    {
        $stages = (new CreativeFunnel)->video($this->metrics([
            'video_views' => 100, 'video_p25' => 40, 'video_p50' => 0, 'video_p75' => 0, 'video_p100' => 0,
        ]))['stages'];

        $this->assertCount(5, $stages);
        $this->assertSame(0, $stages[2]['count']);
    }

    /** An image creative has no watch-through, and is given no empty chart to look at. */
    public function test_a_creative_with_no_video_figures_at_all_has_no_retention_funnel(): void
    {
        $result = (new CreativeFunnel)->video($this->metrics(['impressions' => 500, 'clicks' => 20], reported: []));

        $this->assertSame([], $result['stages']);
    }

    /** A division by a step nobody watched is not a rate — it is left unstated. */
    public function test_no_rate_is_derived_from_a_step_of_zero(): void
    {
        $stages = (new CreativeFunnel)->video($this->metrics([
            'video_views' => 0, 'video_p25' => 0, 'video_p50' => 0, 'video_p75' => 0, 'video_p100' => 0,
        ]))['stages'];

        $this->assertNull($stages[1]['rate_from_previous']);
    }

    /**
     * @param  array<string, int|null>  $figures
     * @param  array<string, bool>|null  $reported
     * @return array<string, mixed>
     */
    private function metrics(array $figures, ?array $reported = null): array
    {
        return $figures + ['reported' => $reported ?? array_map(static fn (): bool => true, $figures)];
    }
}
