<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Support\AlertEventText;
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

    /**
     * EMAIL-PERF-DASHBOARDS-001, then DIGESTS-CADENCE-BUDGETS-001 — an alert's numbers are DRAWN, in the approved digest grammar.
     *
     * The figures strip («167%» beside «80%») gave one finding two ways to print the same number; the approved cards draw it
     * instead — the budget card as plan → spend bars, the cost card as before → now — and no card leads with a stray tile.
     */
    public function test_an_alert_with_figures_draws_them(): void
    {
        $html = (string) MailGallery::render('alerts-bundle', 'en');

        $this->assertSame(0, substr_count($html, 'class="ch-kpi-lead"'));
        // The figures stay, small, under the drawing: the amounts with their currency.
        $this->assertStringContainsString('10,000.00 $', $html);
        $this->assertStringContainsString('8,000.00 $', $html);
        $this->assertStringContainsString('data-block="figures"', $html);
        $this->assertStringContainsString('data-viz="pace"', $html);
        $this->assertStringContainsString('data-viz="before_now"', $html);
        $this->assertStringContainsString('Spent '.AlertEventText::ltr('80%'), $html);
    }

    /** EMAIL-DECISION-REPORT-001 — an alert is an action-required card: the decision it asks for, and the one place to take it. */
    public function test_an_alert_card_names_its_decision_and_links_to_the_alert_and_the_bundle_counts_by_severity(): void
    {
        $html = (string) MailGallery::render('alerts-bundle', 'ar');

        // EMAIL-DIGESTS-OPERATIONAL-001 — every finding now names its next step.
        $this->assertSame(3, substr_count($html, 'data-block="steps"'), 'every finding carries the decision it asks for, in order');
        $this->assertStringContainsString('أبقِ الميزانية', $html);
        $this->assertStringContainsString('أعد ربط المصدر', $html);
        // EMAIL-DIGESTS-OPERATIONAL-001 — the button lands on the exact page: the campaign, or the connections page for a sync.
        $this->assertMatchesRegularExpression('#href="[^"]+/app/campaigns/demo-project/demo-campaign"#', $html);
        $this->assertMatchesRegularExpression('#href="[^"]+/app/projects/demo-project/integrations"#', $html);
        $this->assertStringContainsString('data-block="severity-strip"', $html);
        // DIGESTS-CADENCE-BUDGETS-001 — counted the way the digest counts: a tile per state, its glyph, its word and its number.
        $this->assertMatchesRegularExpression('#✕ عاجل</div>\s*<div[^>]*>2</div>#u', $html);
        $this->assertMatchesRegularExpression('#! انتبه</div>\s*<div[^>]*>1</div>#u', $html);

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
