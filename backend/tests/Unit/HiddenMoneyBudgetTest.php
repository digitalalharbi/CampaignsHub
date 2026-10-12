<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Support\HiddenMoney;
use PHPUnit\Framework\TestCase;

/**
 * CLIENT-LIVE-LINK-SAFETY-001 — a pacing row's planned budget goes with the spend it is measured
 * against. The download blanked `spent` and `pace` and kept `budget`, so a link hiding spend still
 * printed the money planned for each platform. The section flag of the same name is untouched.
 */
final class HiddenMoneyBudgetTest extends TestCase
{
    public function test_a_pacing_rows_budget_is_withheld_with_the_spend(): void
    {
        $data = [
            'budget' => [['provider' => 'meta', 'budget' => 500.0, 'spent' => 100.0, 'pace' => 1.1]],
            'sections' => ['budget' => true],
        ];

        $hidden = HiddenMoney::redact($data, hideSpend: true, hideRevenue: false);

        $this->assertNull($hidden['budget'][0]['budget']);
        $this->assertNull($hidden['budget'][0]['spent']);
        $this->assertSame('meta', $hidden['budget'][0]['provider']);
        $this->assertTrue($hidden['sections']['budget'], 'the section flag named «budget» is not money');

        $kept = HiddenMoney::redact($data, hideSpend: false, hideRevenue: true);
        $this->assertSame(500.0, $kept['budget'][0]['budget'], 'hiding revenue alone does not hide the budget');
    }
}
