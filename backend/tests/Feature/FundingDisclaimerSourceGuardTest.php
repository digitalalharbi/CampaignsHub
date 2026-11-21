<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Funding\Support\FundingDisclaimer;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BUDGET-DISCLAIMER-001 — the financial disclaimer is ONE text in ONE place (`lang/*\/funding.php`, read through
 * `FundingDisclaimer`), and every surface that shows a team member ad spend, budgets or converted money carries it. This fails when
 * a funding export, notice or money mail stops reading it, or when a surface grows its own wording.
 *
 * Not covered on purpose: client-facing shared reports (they show no spend and keep their performance note) and CampaignsHub's own
 * subscription invoices and payment pages.
 */
final class FundingDisclaimerSourceGuardTest extends TestCase
{
    /** @var array<string, string> file => the reference that proves it reads the one text */
    private const SURFACES = [
        'app/Domains/Funding/Services/FundingPdf.php' => 'FundingDisclaimer::text',
        'app/Domains/Funding/Services/FundingExport.php' => 'FundingDisclaimer::text',
        'app/Domains/Funding/Services/FundingStatement.php' => 'FundingDisclaimer::text',
        'app/Domains/Funding/Services/FundingNotifier.php' => 'FundingDisclaimer::text',
        'app/Domains/Funding/Http/Controllers/FundingController.php' => 'FundingDisclaimer::',
        'resources/views/mail/spend-summary.blade.php' => 'FundingDisclaimer::text',
        'resources/views/funding/document.blade.php' => "\$st['disclaimer']",
    ];

    public function test_every_money_surface_reads_the_one_disclaimer(): void
    {
        foreach (self::SURFACES as $file => $needle) {
            $this->assertStringContainsString($needle, File::get(base_path($file)), "{$file} must carry the financial disclaimer");
        }
    }

    public function test_the_text_exists_in_both_languages_and_is_never_copied_into_a_surface(): void
    {
        $ar = FundingDisclaimer::text('ar');
        $en = FundingDisclaimer::text('en');
        $this->assertNotSame('funding.disclaimer', $ar);
        $this->assertNotSame($ar, $en);
        $this->assertStringContainsString('للمتابعة التشغيلية', $ar);
        $this->assertStringContainsString('operational follow-up', $en);

        foreach (array_keys(self::SURFACES) as $file) {
            $src = File::get(base_path($file));
            $this->assertStringNotContainsString('للمتابعة التشغيلية فقط', $src, "{$file} must not restate the wording");
            $this->assertStringNotContainsString('operational follow-up only', $src, "{$file} must not restate the wording");
        }
    }

    public function test_the_disclaimer_is_served_to_the_web_app_from_the_same_text(): void
    {
        $this->assertSame(['ar' => FundingDisclaimer::text('ar'), 'en' => FundingDisclaimer::text('en')], FundingDisclaimer::both());
        $routes = File::get(base_path('routes/api/funding.php'));
        $this->assertStringContainsString("'disclaimer'", $routes);
    }
}
