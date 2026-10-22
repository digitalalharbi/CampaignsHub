<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use Tests\TestCase;

/**
 * EMAIL-DECISION-REPORT-001 × CLIENT-REPORT-FRAMING-001 — the client's digest says what was achieved and
 * never what it cost: no spend tile, no spend on a row, no cost per result, no spend charts, no
 * budget verdicts; results and revenue with their movement, a ratio objective where one is named,
 * and the portal behind the button.
 */
final class ClientDigestTest extends TestCase
{
    /** MEMBER-VISIBILITY-001 — an agency member whose membership withholds the money reads the same digest without it, and keeps the agency's own button. */
    public function test_a_restricted_member_gets_no_money_and_keeps_the_agency_button(): void
    {
        $html = (string) MailGallery::render('digest-restricted-daily', 'en');

        $this->assertStringNotContainsString('41,833', $html);
        $this->assertStringNotContainsString('23,333', $html);
        $this->assertStringNotContainsString('Cost per result', $html);
        $this->assertStringNotContainsString('data-block="chart-trend"', $html);
        $this->assertStringNotContainsString('data-block="chart-donut"', $html);
        $this->assertStringNotContainsString('data-block="spend-share"', $html);
        $this->assertStringContainsString('96,500', $html, 'revenue stays: it is the outcome, not the money that bought it');
        $this->assertStringContainsString('Open your portfolio board', $html, 'the agency button, not the client portal');
        $this->assertStringNotContainsString('/portal"', $html);
    }

    public function test_the_client_weekly_carries_no_spend_no_cost_and_no_spend_chart_and_leads_with_outcomes(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $ar = $locale === 'ar';
            $html = (string) MailGallery::render('digest-client-weekly', $locale);
            $agency = (string) MailGallery::render('digest-weekly', $locale);

            // The agency mail states the portfolio's spend; the client mail never does.
            $this->assertStringContainsString('41,833', $agency);
            $this->assertStringNotContainsString('41,833', $html, "[$locale] the account spend figure reached the client");
            $this->assertStringNotContainsString('23,333', $html, "[$locale] a project's spend reached the client");
            $this->assertStringNotContainsString($ar ? 'تكلفة النتيجة' : 'Cost per result', $html, "[$locale] a cost per result reached the client");
            $this->assertStringNotContainsString('data-block="spend-share"', $html);
            $this->assertStringNotContainsString('data-block="chart-trend"', $html);
            $this->assertStringNotContainsString('data-block="chart-donut"', $html);
            $this->assertStringNotContainsString($ar ? 'أسرع من الخطة' : 'ahead of plan', $html, "[$locale] a budget verdict reached the client");

            // Outcomes first: results and revenue with movement, the portfolio rows, the portal button.
            $this->assertStringContainsString('96,500', $html, "[$locale] revenue is the client's own outcome");
            $this->assertStringContainsString('data-block="portfolio-row"', $html);
            $this->assertStringContainsString($ar ? 'النتائج' : 'Results', $html);
            $this->assertMatchesRegularExpression('#href="[^"]+/portal"#', $html, "[$locale] the button lands on the portal");
            $this->assertStringContainsString($ar ? 'افتح بوابتك' : 'Open your portal', $html);
            $this->assertStringContainsString($ar ? 'مقارنة بالفترة السابقة' : 'against the previous period', $html, "[$locale] the verdict is said in outcomes");
        }
    }
}
