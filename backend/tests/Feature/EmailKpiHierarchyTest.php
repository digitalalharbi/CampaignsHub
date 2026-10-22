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
            // EMAIL-DECISION-REPORT-001 — each portfolio row leads with its objective figure at 22px.
            $projects = substr_count($html, 'class="ch-kpi-lead" style="font-size:22px');

            $this->assertGreaterThanOrEqual(2, $leads, "[$locale] the account line and at least one project lead with a large figure");
            $this->assertSame(1, substr_count($html, 'class="ch-kpi-lead" style="font-size:30px'), "[$locale] exactly one account-level lead");
            $this->assertGreaterThanOrEqual(1, $projects, "[$locale] each project leads with its own primary figure");
        }
    }

    /** EMAIL-PERF-DASHBOARDS-001 — one project is not a portfolio: it leads itself, with no roll-up and no ranking of one. */
    public function test_a_single_project_digest_leads_with_the_project_and_carries_no_roll_up(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = (string) MailGallery::render('digest-single-project', $locale);

            $this->assertSame(1, substr_count($html, 'class="ch-kpi-lead" style="font-size:30px'), "[$locale] exactly one lead, at display size");
            $this->assertSame(0, substr_count($html, 'class="ch-kpi-lead" style="font-size:22px'), "[$locale] no second-tier lead: the project row is the hero");
            $this->assertStringNotContainsString($locale === 'ar' ? 'إجمالي الحساب' : 'Account total', $html, "[$locale] no account roll-up above a single project");
            $this->assertStringContainsString($locale === 'ar' ? 'ملخص الفترة لمشروع' : 'The period for', $html, "[$locale] the intro names the project");
        }
    }

    /** EMAIL-PERF-DASHBOARDS-001 — an alert leads with the rule's own number; a finding without one keeps its sentence alone. */
    public function test_an_alert_with_figures_leads_with_them_and_one_without_does_not(): void
    {
        $html = (string) MailGallery::render('alerts-bundle', 'en');

        // Three findings; only the budget-pace one carries figures.
        $this->assertSame(1, substr_count($html, 'class="ch-kpi-lead"'));
        $this->assertStringContainsString('167%', $html);
        $this->assertStringContainsString('10,000.00 SAR', $html);
    }

    /** EMAIL-DECISION-REPORT-001 — an alert is an action-required card: the decision it asks for, and the one place to take it. */
    public function test_an_alert_card_names_its_decision_and_links_to_the_alert_and_the_bundle_counts_by_severity(): void
    {
        $html = (string) MailGallery::render('alerts-bundle', 'ar');

        $this->assertSame(2, substr_count($html, 'data-block="action"'), 'the two findings with a decision carry it; the third has none');
        $this->assertStringContainsString('قرار اليوم: أبقِ الميزانية', $html);
        $this->assertStringContainsString('أعد ربط المصدر', $html);
        $this->assertMatchesRegularExpression('#href="[^"]+/app/alerts\?event=demo-1"#', $html, 'the button lands on the alert itself (OPS-LINK-001 deep link)');
        $this->assertStringContainsString('data-block="severity-strip"', $html);
        $this->assertMatchesRegularExpression('#2 حرج#', $html);
        $this->assertMatchesRegularExpression('#1 تحذير#', $html);

        $one = (string) MailGallery::render('alerts-one', 'en');
        $this->assertStringNotContainsString('data-block="severity-strip"', $one, 'one finding is not a count');
    }

    public function test_the_weekly_digest_keeps_the_same_hierarchy(): void
    {
        $html = (string) MailGallery::render('digest-weekly', 'en');

        $this->assertStringContainsString('class="ch-kpi-lead"', $html);
        // The secondary figures are set smaller than the lead.
        $this->assertStringContainsString('font-size:15px; font-weight:700', $html);
    }
}
