<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Support\FundingMoney;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BUDGET-CURRENCY-001 — every money figure of the budgets surfaces carries a short currency mark, and that mark comes from ONE class.
 * A Funding output that formats a figure with its own `number_format` is the next «1,234» with no unit, so this fails on it.
 */
final class FundingMoneySourceGuardTest extends TestCase
{
    public function test_the_marks_are_short_and_the_same_in_both_languages(): void
    {
        $this->assertSame('ر.س', FundingMoney::mark('SAR'));
        $this->assertSame('$', FundingMoney::mark('usd'));
        $this->assertSame('€', FundingMoney::mark('EUR'));
        $this->assertSame('AED', FundingMoney::mark('aed'), 'any other code is shown as its ISO code');
        $this->assertSame('1,234.50 ر.س', FundingMoney::format(1234.5, 'SAR'));
        $this->assertSame('1,235 $', FundingMoney::format(1234.5, 'USD', 0));
        $this->assertSame('—', FundingMoney::format(null));
        $this->assertSame('#,##0.00 "ر.س"', FundingMoney::excelFormat('SAR'));
    }

    public function test_no_funding_output_formats_money_on_its_own(): void
    {
        $files = collect(File::allFiles(app_path('Domains/Funding')))->map(fn ($f) => $f->getPathname())
            ->push(resource_path('views/funding/document.blade.php'))
            ->reject(fn (string $p) => str_ends_with($p, 'Support/FundingMoney.php'));

        foreach ($files as $path) {
            $this->assertStringNotContainsString('number_format(', File::get($path), basename($path).' must format money through FundingMoney');
        }
    }
}
