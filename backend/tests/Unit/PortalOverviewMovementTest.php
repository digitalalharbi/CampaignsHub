<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\ClientWorkspaces\Services\PortalOverview;
use PHPUnit\Framework\TestCase;

/**
 * CP-PARITY-001 — the client portfolio's movement pills come from the analytics service's own comparison.
 *
 * The advertiser's cards carry a movement against the previous window; the client's did not, because the portal
 * endpoint never passed one on. `PortalOverview` now passes the service's `delta` through under the SAME visibility and
 * whole-money rules as the figures — one movement per figure offered, nothing computed in the portal layer, so the
 * client's pill and the agency's cannot disagree.
 */
final class PortalOverviewMovementTest extends TestCase
{
    public function test_the_movement_follows_the_figures_offered_and_the_whole_money_rule(): void
    {
        // What `for()` offers once visibility and the whole-money rule have been applied: revenue withheld (null), hence roas too.
        $kpis = ['impressions' => 1000.0, 'clicks' => 50.0, 'conversions' => 5.0, 'ctr' => 0.05, 'spend' => 400.0, 'cpa' => 80.0, 'revenue' => null, 'roas' => null];
        $delta = ['impressions' => 0.12, 'clicks' => -0.08, 'conversions' => null, 'ctr' => 0.004, 'spend' => 0.2, 'cpa' => -0.1, 'revenue' => 0.5, 'roas' => 0.3, 'reach' => 0.9];

        $movement = PortalOverview::movementFor($kpis, $delta);

        $this->assertSame(0.12, $movement['impressions']);
        $this->assertSame(-0.08, $movement['clicks']);
        $this->assertNull($movement['conversions'], 'no previous figure, no movement');
        $this->assertSame(0.2, $movement['spend'], 'spend is whole and shown, so its movement is too');
        $this->assertNull($movement['revenue'], 'a withheld figure has no movement either');
        $this->assertNull($movement['roas']);
        $this->assertSame(array_keys($kpis), array_keys($movement), 'one movement key per figure offered — reach, never offered, never appears');
    }

    public function test_no_comparison_means_no_movement_at_all(): void
    {
        $this->assertNull(PortalOverview::movementFor(['impressions' => 1000.0], null), 'a mixed-currency space is not compared, so the page draws no pill');
    }
}
