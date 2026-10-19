<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\MailGallery;
use Tests\TestCase;

/**
 * EMAIL-PERF-DASHBOARDS-001 — a performance email leads with ONE large figure and carries the rest as a
 * compact strip, the same hierarchy the web's Platform view uses. Equal-weight cards make the reader do
 * the ranking the email should have done.
 */
final class EmailKpiHierarchyTest extends TestCase
{
    public function test_the_daily_digest_leads_with_one_large_figure_for_the_account_and_one_per_project(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = (string) MailGallery::render('digest-daily', $locale);

            $leads = substr_count($html, 'class="ch-kpi-lead"');
            $projects = substr_count($html, 'class="ch-kpi-lead" style="font-size:28px');

            $this->assertGreaterThanOrEqual(2, $leads, "[$locale] the account line and at least one project lead with a large figure");
            $this->assertSame(1, substr_count($html, 'class="ch-kpi-lead" style="font-size:30px'), "[$locale] exactly one account-level lead");
            $this->assertGreaterThanOrEqual(1, $projects, "[$locale] each project leads with its own primary figure");
        }
    }

    public function test_the_weekly_digest_keeps_the_same_hierarchy(): void
    {
        $html = (string) MailGallery::render('digest-weekly', 'en');

        $this->assertStringContainsString('class="ch-kpi-lead"', $html);
        // The secondary figures are set smaller than the lead.
        $this->assertStringContainsString('font-size:15px; font-weight:700', $html);
    }
}
