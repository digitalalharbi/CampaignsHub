<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Commerce\Services\StoreFunnelExplanation;
use PHPUnit\Framework\TestCase;

/**
 * FUNNEL-ANALYTICAL-PATTERN-001 — the funnel's own reading, and the falls it refuses to invent.
 *
 * The section was a row of stages with a number under each; the drop between two of them is the only
 * thing anybody opens it for, and it was left for the reader to compute from figures whose sources
 * differ — the click is the platform's claim, the order is the merchant's ledger.
 *
 * The rule that matters more than the arithmetic: a stage nobody measured cannot be an end of a
 * drop. Reading it as zero produces a hundred-per-cent fall into it — the biggest number on the page,
 * certainly false, and sitting exactly where the data is weakest.
 */
final class StoreFunnelExplanationTest extends TestCase
{
    private function stage(string $key, ?float $value, string $kind = 'stores', string $state = 'measured'): array
    {
        return [
            'key' => $key,
            'label_ar' => $key,
            'label_en' => $key,
            'value' => $value,
            'state' => $value === null ? 'unavailable' : $state,
            'source' => ['kind' => $value === null ? 'none' : $kind],
        ];
    }

    private function explain(array $stages): array
    {
        return (new StoreFunnelExplanation)->explain($stages);
    }

    public function test_the_signal_is_the_largest_measured_fall(): void
    {
        $out = $this->explain([
            $this->stage('clicks', 1000.0, 'ad_platforms'),
            $this->stage('sessions', 900.0),
            $this->stage('carts', 300.0),
            $this->stage('orders', 240.0),
        ]);

        $this->assertSame('sessions', $out['signal']['from']['key']);
        $this->assertSame('carts', $out['signal']['to']['key']);
        $this->assertEqualsWithDelta(0.6667, $out['signal']['share'], 0.001);
        $this->assertNull($out['silent_reason']);
    }

    /**
     * A stage nobody measured is not a fall to zero.
     *
     * It is the biggest number the page could show and the one thing on it that is certainly false —
     * and it would appear exactly where the data is weakest, which is where a reader trusts a large
     * number most.
     */
    public function test_an_unmeasured_stage_is_not_a_total_collapse(): void
    {
        $out = $this->explain([
            $this->stage('clicks', 1000.0, 'ad_platforms'),
            $this->stage('sessions', null),
            $this->stage('orders', 200.0),
        ]);

        $this->assertSame('clicks', $out['signal']['from']['key']);
        $this->assertSame('orders', $out['signal']['to']['key']);
        $this->assertSame(1, $out['unmeasured_stages']);
    }

    /**
     * Whether the two ends share a source changes what the drop MEANS.
     *
     * Platform click to merchant order is partly measurement; merchant cart to merchant order is
     * not, and an operator reads those differently.
     */
    public function test_it_says_when_the_two_ends_come_from_different_systems(): void
    {
        $mixed = $this->explain([
            $this->stage('clicks', 1000.0, 'ad_platforms'),
            $this->stage('orders', 100.0, 'stores'),
        ]);
        $this->assertFalse($mixed['signal']['same_source']);
        $this->assertStringContainsString('measurement rather than behaviour', $mixed['explanation']['en']);

        $same = $this->explain([
            $this->stage('carts', 500.0),
            $this->stage('orders', 100.0),
        ]);
        $this->assertTrue($same['signal']['same_source']);
        $this->assertStringContainsString('behaviour rather than a difference in measurement', $same['explanation']['en']);
    }

    /** A stage that GREW is not a fall of minus-something. */
    public function test_a_stage_that_grew_is_not_a_drop(): void
    {
        $out = $this->explain([
            $this->stage('clicks', 100.0, 'ad_platforms'),
            $this->stage('sessions', 140.0),
        ]);

        $this->assertNull($out['signal']);
        $this->assertSame('no_stage_fell', $out['silent_reason']);
    }

    /** «One stage measured» and «none» are different situations, and are named separately. */
    public function test_it_separates_one_measured_stage_from_none(): void
    {
        $this->assertSame(
            'only_one_stage_is_measured',
            $this->explain([$this->stage('orders', 10.0), $this->stage('carts', null)])['silent_reason'],
        );

        $this->assertSame(
            'no_stage_could_be_measured',
            $this->explain([$this->stage('orders', null), $this->stage('carts', null)])['silent_reason'],
        );
    }
}
