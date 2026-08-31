<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\AdsExplanation;
use PHPUnit\Framework\TestCase;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the ads section, read back in the funnel's own shape.
 *
 * The ranked grid is the signal and nothing else. What this adds is the range it implies, what that
 * range is measured on, why the distance exists, the figures it rests on, and the single action the
 * evidence supports — and, more importantly, the three situations where it must say nothing.
 */
final class AdsExplanationTest extends TestCase
{
    private function ad(string $id, string $name, string $metric, float $value): array
    {
        return ['id' => $id, 'name' => $name, 'metric' => $metric, 'value' => $value];
    }

    private function explain(array $best, array $worst, string $objective = 'sales'): array
    {
        return (new AdsExplanation)->explain($best, $worst, $objective);
    }

    public function test_the_signal_is_a_range_read_on_one_metric(): void
    {
        $out = $this->explain(
            [$this->ad('a', 'Eid film', 'roas', 6.2)],
            [$this->ad('b', 'Old banner', 'roas', 0.8)],
        );

        $this->assertSame('roas', $out['signal']['metric']);
        $this->assertSame('Eid film', $out['signal']['best']['ad']);
        $this->assertSame('Old banner', $out['signal']['worst']['ad']);
        $this->assertSame(['spend', 'roas'], $out['evidence']);
        $this->assertStringContainsString('Old banner', $out['action']['en']);
    }

    /**
     * With two or three ads the ranker's best is arithmetically also its worst.
     *
     * Printing one ad as both ends of a range is a comparison with itself, and a reader takes it for
     * a comparison with something.
     */
    public function test_one_ad_is_not_both_ends_of_a_range(): void
    {
        $only = $this->ad('a', 'Eid film', 'roas', 6.2);

        $out = $this->explain([$only], [$only]);

        $this->assertNull($out['signal']);
        $this->assertNull($out['action']);
        $this->assertSame('only_one_ad_is_comparable', $out['silent_reason']);
    }

    /**
     * A range whose ends were measured on different metrics is not a range.
     *
     * It happens when one row carries a metric the other does not, and stating it as «6.2 against
     * 34 SAR» is two units pretending to be a comparison.
     */
    public function test_two_ends_measured_differently_are_not_compared(): void
    {
        $out = $this->explain(
            [$this->ad('a', 'Eid film', 'roas', 6.2)],
            [$this->ad('b', 'Old banner', 'cpa', 34.0)],
        );

        $this->assertNull($out['signal']);
        $this->assertSame('the_two_ends_were_measured_on_different_metrics', $out['silent_reason']);
    }

    /** Nothing ranked at all is its own silence, and says so. */
    public function test_nothing_ranked_is_stated_as_such(): void
    {
        $this->assertSame('no_ad_could_be_ranked', $this->explain([], [])['silent_reason']);
    }

    /**
     * The action names both ends and stops.
     *
     * «Pause the weakest» is a decision with a client's money in it — and a creative the client may
     * have paid to make. The product's part is to put the two in front of whoever decides.
     */
    public function test_the_action_recommends_a_comparison_not_a_verdict(): void
    {
        $out = $this->explain(
            [$this->ad('a', 'Eid film', 'roas', 6.2)],
            [$this->ad('b', 'Old banner', 'roas', 0.8)],
        );

        $this->assertStringNotContainsStringIgnoringCase('pause', $out['action']['en']);
        $this->assertStringNotContainsStringIgnoringCase('stop', $out['action']['en']);
        $this->assertStringContainsString('Compare', $out['action']['en']);
    }
}
