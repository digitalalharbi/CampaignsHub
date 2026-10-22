<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Notifications\Support\AlertFigures;
use PHPUnit\Framework\TestCase;

/** EMAIL-PERF-DASHBOARDS-001 — the figures are read from the rule's own context, never recomputed, and absent where none exist. */
final class AlertFiguresTest extends TestCase
{
    public function test_budget_pace_leads_with_the_share_of_expected_spend(): void
    {
        $f = AlertFigures::from(['basis_class' => 'measured', 'spend' => 8000.0, 'budget' => 10000.0, 'ratio' => 1.67, 'budget_currency' => 'SAR'], false);
        $this->assertSame('167%', $f['lead']['value']);
        $this->assertSame(['Spent' => '8,000.00 SAR', 'Budget' => '10,000.00 SAR'], array_column($f['strip'], 'value', 'label'));
        $this->assertNull(AlertFigures::from(['basis_class' => 'unmeasurable', 'pacing_basis' => 'currency_mismatch', 'budget' => 1.0], false), 'an unmeasurable plan has no share to state');
    }

    public function test_a_spend_limit_leads_with_what_was_consumed(): void
    {
        $f = AlertFigures::from(['consumed' => 4500.5, 'limit' => 5000.0, 'threshold' => 90, 'currency' => 'USD'], true);
        $this->assertSame('4,500.50 USD', $f['lead']['value']);
        $this->assertSame(['الحدّ' => '5,000.00 USD', 'العتبة' => '90%'], array_column($f['strip'], 'value', 'label'));
    }

    public function test_a_rate_now_against_before_carries_the_change_and_the_window(): void
    {
        $f = AlertFigures::from(['roas_current' => 2.1, 'roas_previous' => 3.0, 'threshold_pct' => 25, 'window_days' => 7], false);
        $this->assertSame('Return on ad spend now', $f['lead']['label']);
        $this->assertSame('2.10×', $f['lead']['value']);
        $this->assertSame(['Before' => '3.00×', 'Change' => '-30%', 'Window' => '7 days'], array_column($f['strip'], 'value', 'label'));

        $ctr = AlertFigures::from(['ctr_current' => 0.012, 'ctr_previous' => 0.02], false);
        $this->assertSame('1.2%', $ctr['lead']['value']);
    }

    public function test_a_sync_or_token_finding_has_no_figures(): void
    {
        $this->assertNull(AlertFigures::from(['provider' => 'snapchat', 'sync_run_id' => 'x', 'error' => 'timeout'], false));
        $this->assertNull(AlertFigures::from(['provider' => 'meta', 'expires_at' => '2026-10-30T00:00:00Z'], true));
        $this->assertNull(AlertFigures::from([], true));
    }
}
