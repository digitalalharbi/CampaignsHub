<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Models\ReportShare;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-SPEND-VISIBILITY-001 — report setup's KPI selection beats the legacy `hide_spend` default.
 */
final class ReportShareSpendVisibilityTest extends TestCase
{
    private function share(bool $hide, ?array $scope): ReportShare
    {
        $share = new ReportShare;
        $share->setRawAttributes(['hide_spend' => $hide ? 1 : 0, 'scope' => $scope === null ? null : json_encode($scope)]);

        return $share;
    }

    public function test_spend_selected_in_setup_is_shown_whatever_the_legacy_flag_says(): void
    {
        $this->assertFalse($this->share(true, ['metrics' => ['spend', 'impressions']])->hide_spend);
    }

    public function test_spend_not_selected_keeps_the_legacy_flag(): void
    {
        $this->assertTrue($this->share(true, ['metrics' => ['impressions', 'clicks']])->hide_spend);
        $this->assertTrue($this->share(true, null)->hide_spend, 'a snapshot share keeps its flag');
    }

    public function test_a_flag_that_was_off_stays_off(): void
    {
        $this->assertFalse($this->share(false, ['metrics' => ['impressions']])->hide_spend);
    }
}
