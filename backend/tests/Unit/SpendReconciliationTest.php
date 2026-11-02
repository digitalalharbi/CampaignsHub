<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Support\SpendReconciliation;
use PHPUnit\Framework\TestCase;

/** SPEND-ACCURACY-001 — the comparison says exactly where provider and stored spend differ, with the numbers. */
final class SpendReconciliationTest extends TestCase
{
    public function test_equal_sets_have_no_difference(): void
    {
        $rows = [['campaign_id' => 'a', 'date' => '2026-10-25', 'spend' => 10.5], ['campaign_id' => 'a', 'date' => '2026-10-26', 'spend' => 20]];
        $r = SpendReconciliation::compare($rows, $rows);

        $this->assertSame(30.5, $r['provider_total']);
        $this->assertSame(0.0, $r['diff']);
        $this->assertSame(0.0, $r['diff_pct']);
        $this->assertSame([], $r['only_provider']);
        $this->assertSame([], $r['only_stored']);
    }

    public function test_a_missing_day_a_stale_day_and_a_foreign_campaign_are_each_named(): void
    {
        $provider = [
            ['campaign_id' => 'a', 'date' => '2026-10-25', 'spend' => 50],
            ['campaign_id' => 'a', 'date' => '2026-10-26', 'spend' => 99.07],
            ['campaign_id' => 'b', 'date' => '2026-10-26', 'spend' => 10],
        ];
        $stored = [
            ['campaign_id' => 'a', 'date' => '2026-10-25', 'spend' => 50],
            ['campaign_id' => 'a', 'date' => '2026-10-26', 'spend' => 80.00],   // stale: provider revised it up
            ['campaign_id' => 'x', 'date' => '2026-10-26', 'spend' => 5],       // not at the provider
        ];
        $r = SpendReconciliation::compare($provider, $stored);

        $this->assertSame(159.07, $r['provider_total']);
        $this->assertSame(135.0, $r['stored_total']);
        $this->assertSame(-24.07, $r['diff']);
        $this->assertSame(-15.13, $r['diff_pct']);
        $this->assertSame(['b'], $r['only_provider']);
        $this->assertSame(['x'], $r['only_stored']);
        $this->assertSame(-24.07, $r['days'][1]['diff'], '26 Oct: provider 109.07, stored 85.00');
        $this->assertSame(0.0, $r['days'][0]['diff']);
        $this->assertSame('a', $r['campaigns'][0]['campaign_id'], 'the largest absolute difference comes first');
        $this->assertSame(-19.07, $r['campaigns'][0]['diff']);
    }

    public function test_an_empty_provider_answer_has_no_percentage_to_state(): void
    {
        $r = SpendReconciliation::compare([], [['campaign_id' => 'a', 'date' => '2026-10-25', 'spend' => 5]]);

        $this->assertNull($r['diff_pct']);
        $this->assertSame(5.0, $r['diff']);
    }
}
