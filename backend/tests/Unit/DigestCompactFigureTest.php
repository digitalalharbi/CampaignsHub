<?php

namespace Tests\Unit;

use App\Domains\Notifications\Support\OperationalDigestModel;
use PHPUnit\Framework\TestCase;

/** DIGEST-PROJECT-CARDS-001 — Owner 2026-11-23: the project cards show short figures so the tiles fit. */
class DigestCompactFigureTest extends TestCase
{
    public function test_large_figures_are_shortened_and_keep_their_marks(): void
    {
        $this->assertSame('$ 125.9K', OperationalDigestModel::compactFigure('$ 125,943'));
        $this->assertSame('1.25M', OperationalDigestModel::compactFigure('1,250,000'));
        $this->assertSame('19.2K ر.س', OperationalDigestModel::compactFigure('19,213 ر.س'));
        $this->assertSame('12K USD', OperationalDigestModel::compactFigure('12,000.50 USD'));
    }

    public function test_small_missing_and_ratio_figures_are_untouched(): void
    {
        foreach (['876', '43.14 $', '$ 9,999', '33.17×', '0.61%', '—', 'لا بيانات'] as $v) {
            $this->assertSame($v, OperationalDigestModel::compactFigure($v));
        }
    }
}
