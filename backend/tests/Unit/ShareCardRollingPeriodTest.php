<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Support\ReportPeriod;
use PHPUnit\Framework\TestCase;

/**
 * SHARE-CARD-ROLLING-001 — a rolling link's preview names its window and that it moves; only a fixed link states dates.
 *
 * Owner 2026-11-17 (كرم النخيل): the chat kept «من — إلى» on the cover while the link itself moved on.
 */
final class ShareCardRollingPeriodTest extends TestCase
{
    public function test_a_fixed_link_states_its_dates(): void
    {
        $this->assertSame('2026-11-04 — 2026-11-17', ReportPeriod::cardLabel('fixed', null, '2026-11-04', '2026-11-17', 'ar'));
    }

    public function test_a_rolling_link_states_its_window_and_that_it_moves(): void
    {
        $this->assertSame('آخر 30 يومًا · يتحدّث تلقائيًا', ReportPeriod::cardLabel('rolling', null, '2026-10-19', '2026-11-17', 'ar'));
        $this->assertSame('آخر 7 أيام · يتحدّث تلقائيًا', ReportPeriod::cardLabel('rolling', 'custom', '2026-11-11', '2026-11-17', 'ar'));
        $this->assertSame('تقرير شهري · يتحدّث تلقائيًا', ReportPeriod::cardLabel('rolling', 'monthly', '2026-11-01', '2026-11-17', 'ar'));
        $this->assertSame('Last 7 days · updates automatically', ReportPeriod::cardLabel('rolling', null, '2026-11-11', '2026-11-17', 'en'));
    }

    public function test_a_rolling_label_never_carries_a_date(): void
    {
        $label = (string) ReportPeriod::cardLabel('rolling', 'weekly', '2026-11-10', '2026-11-16', 'ar');

        $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $label);
    }
}
