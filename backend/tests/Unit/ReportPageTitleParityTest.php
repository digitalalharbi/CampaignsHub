<?php

namespace Tests\Unit;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportIdentity;
use Tests\TestCase;

/**
 * One report title, two writers. The server names the pasted-link card, the XLSX and the PDF;
 * the SPA names the tab. If either drifts, a client sees «Q3 — كامبينز هب» in the tab and
 * «Q3 · CampaignsHub» on the card for the same document — so the SPA's source is read and held
 * to what the server produces, rather than trusted to agree.
 */
class ReportPageTitleParityTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = base_path('../frontend/src/'.$relative);
        $this->assertFileExists($path, "the SPA file this parity is held against moved: {$relative}");

        return (string) file_get_contents($path);
    }

    public function test_the_spa_builds_the_title_the_way_the_server_does(): void
    {
        $titles = $this->source('features/reports/sharedBranding.ts');
        $brand = $this->source('lib/brand.ts');

        $this->assertStringContainsString('`${title} — ${productName(locale)}`', $titles, 'the SPA stopped joining name and product with « — »');
        $this->assertStringContainsString("'Performance report'", $titles);
        $this->assertStringContainsString("'تقرير الأداء'", $titles);
        $this->assertStringContainsString("nameAr: '".ReportIdentity::productName('ar')."'", $brand);
        $this->assertStringContainsString("nameEn: '".ReportIdentity::productName('en')."'", $brand);

        $this->assertSame('Q3 — كامبينز هب', ReportIdentity::pageTitle(new Report(['name' => ' Q3 ', 'config' => ['locale' => 'ar']])));
        $this->assertSame('Performance report — CampaignsHub', ReportIdentity::pageTitle(new Report(['name' => '', 'config' => ['locale' => 'en']])));
    }
}
