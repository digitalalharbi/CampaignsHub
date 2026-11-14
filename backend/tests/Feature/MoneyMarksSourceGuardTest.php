<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Support\FundingMoney;
use App\Domains\Integrations\Measurement\Ga4DigestBlock;
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Notifications\Support\AlertFigures;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * MONEY-MARKS-001 — every money figure shows its SHORT currency mark beside the number (ر.س / $ / € / the ISO code), in mails,
 * digests, alert lines, notices and PDFs; percentages, counts and ROAS never do. The mark comes from `FundingMoney`; a file that glues
 * a currency CODE to a number on its own prints «1,234 SAR» where the product says «1,234 ر.س».
 */
final class MoneyMarksSourceGuardTest extends TestCase
{
    public function test_with_mark_puts_the_mark_after_the_figure_and_never_guesses_a_currency(): void
    {
        $this->assertSame('1,234 ر.س', FundingMoney::withMark('1,234', 'SAR'));
        $this->assertSame('1,234 $', FundingMoney::withMark('1,234', 'usd'));
        $this->assertSame('12 €', FundingMoney::withMark('12', 'EUR'));
        $this->assertSame('12 AED', FundingMoney::withMark('12', 'aed'));
        $this->assertSame('12', FundingMoney::withMark('12', null));
        $this->assertSame('12', FundingMoney::withMark('12', ' '));
    }

    public function test_the_disclaimer_rides_only_with_a_money_figure(): void
    {
        $this->assertTrue(FundingMoney::textHasMoney('Spent 1,200.00 $ / 5,000.00 $'));
        $this->assertTrue(FundingMoney::textHasMoney('الإنفاق 800 ر.س'));
        $this->assertTrue(FundingMoney::textHasMoney('Spent 12 AED'));
        $this->assertFalse(FundingMoney::textHasMoney('Before 4.56× Now 3.10× · alert at 25%'));
        $this->assertFalse(FundingMoney::textHasMoney('Last figures dated 2026-08-04'));
    }

    public function test_digest_money_carries_the_mark_and_ratios_do_not(): void
    {
        $en = new DigestPresenter('en', 30, 'USD');
        $this->assertSame('1,234.50 $', $en->money(1234.5));
        $this->assertSame('99,093 $', $en->headline(99092.98));
        $ar = new DigestPresenter('ar', 30, 'SAR');
        $this->assertSame('800 ر.س', $ar->money(800));
        $this->assertSame('12,400 ر.س', $ar->headline(12400.4));
        $this->assertSame('3.10×', $en->ratio(3.1));
        $this->assertSame('1,000', (new DigestPresenter('en', 30, null))->money(1000), 'no currency stated: the figure goes bare, never a guessed one');
    }

    public function test_alert_figures_and_ga4_line_use_the_mark(): void
    {
        $f = AlertFigures::from(['basis_class' => 'measured', 'ratio' => 1.67, 'spend' => 8000.0, 'budget' => 10000.0, 'budget_currency' => 'SAR'], true);
        $this->assertSame('8,000.00 ر.س', $f['strip'][0]['value']);
        $this->assertSame('10,000.00 ر.س', $f['strip'][1]['value']);
        $this->assertSame('167%', $f['lead']['value'], 'a percentage carries no currency mark');

        $line = Ga4DigestBlock::line(['sessions' => 100.0, 'purchases' => 4.0, 'revenue_usd' => 1500.0, 'claimed' => null, 'gap' => null], false);
        $this->assertStringContainsString('1,500 $', (string) $line);
    }

    /**
     * Files that build a money string with `.' '.$currency` (or «{$x->currency}») after a number_format. Allowlist: CampaignsHub's OWN
     * subscription / billing / payment / invoice code and registration pricing (what CampaignsHub charges, a contract figure), the
     * platform operator's console, the CLI diagnostics (developer output, not product) and the Funding helper itself.
     */
    private const ALLOWED = [
        'Domains/Subscriptions/', 'Domains/Billing/', 'Domains/Accounts/Services/Registration', 'Domains/Platform/',
        'Domains/Identity/Services/AuthCodeSwitches.php', 'Domains/Integrations/Console/', 'Domains/Campaigns/Console/',
        'Domains/Funding/Support/FundingMoney.php',
    ];

    public function test_no_product_output_glues_a_currency_code_to_a_number_on_its_own(): void
    {
        $pattern = '/number_format\([^;]*\)\s*\.\s*\' \'\s*\.\s*\(?\$[\w>\-\[\]\'\(\)\? ]*[cC]urrency|number_format\([^;]*\)\s*\.\s*"\s*\{\$[\w>\-]*[cC]urrency\}/';
        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            $rel = str_replace(app_path().'/', '', $file->getPathname());
            if (collect(self::ALLOWED)->contains(fn (string $a) => str_starts_with($rel, $a))) {
                continue;
            }
            foreach (preg_split('/\R/', File::get($file->getPathname())) as $i => $line) {
                if (preg_match($pattern, $line)) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }
        foreach (File::allFiles(resource_path('views')) as $file) {
            $rel = 'views/'.str_replace(resource_path('views').'/', '', $file->getPathname());
            if (str_starts_with($rel, 'views/invoices/')) { // CampaignsHub's own invoice document
                continue;
            }
            foreach (preg_split('/\R/', File::get($file->getPathname())) as $i => $line) {
                if (preg_match('/number_format\([^}]*\)\s*\}\}\s*\{\{\s*\$[\w>\-]*[cC]urrency/', $line)) {
                    $offenders[] = $rel.':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'format money through FundingMoney::withMark / format');
    }

    public function test_the_money_mails_carry_the_one_disclaimer_and_a_client_report_does_not(): void
    {
        foreach (['mail/daily-digest.blade.php', 'mail/alerts.blade.php', 'mail/spend-summary.blade.php'] as $view) {
            $src = File::get(resource_path('views/'.$view));
            $this->assertStringContainsString('FundingDisclaimer::text', $src, $view.' must carry the financial disclaimer');
            $this->assertStringNotContainsString('للمتابعة التشغيلية فقط', $src, $view.' must not restate the wording');
        }
        // Client-facing surfaces keep their own performance note: the one disclaimer is never imported there.
        foreach (['reports/document.blade.php', 'mail/scheduled-report.blade.php'] as $view) {
            $this->assertStringNotContainsString('FundingDisclaimer', File::get(resource_path('views/'.$view)), $view.' is client-facing');
        }
        $this->assertStringContainsString('$noMoney', File::get(resource_path('views/mail/daily-digest.blade.php')));
    }
}
