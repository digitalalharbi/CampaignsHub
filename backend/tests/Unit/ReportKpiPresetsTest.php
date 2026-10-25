<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportTemplateEngine;
use Tests\TestCase;

/**
 * REPORT-KPI-PRESETS-001 — each objective, and where a lead's result happens, picks its own KPIs.
 */
final class ReportKpiPresetsTest extends TestCase
{
    private function engine(): ReportTemplateEngine
    {
        return app(ReportTemplateEngine::class);
    }

    public function test_every_objective_has_the_owners_preset(): void
    {
        $e = $this->engine();

        $this->assertSame(['impressions', 'reach', 'frequency', 'cpm'], $e->metricSet('awareness'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'ctr', 'cpc', 'landing_page_views'], $e->metricSet('traffic'));
        $this->assertSame(['spend', 'impressions', 'engagements', 'engagement_rate', 'cpe'], $e->metricSet('engagement'));
        // The Owner's sales funnel (REPORT-OBJECTIVE-TEMPLATE-001): the cost per purchase is its own show/hide switch, not a mandatory card.
        $this->assertSame(['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], $e->metricSet('sales'));
        $this->assertSame(['spend', 'installs', 'cpi', 'registrations'], $e->metricSet('app_installs'));
    }

    public function test_leads_follow_their_destination(): void
    {
        $e = $this->engine();

        $this->assertSame(['spend', 'impressions', 'leads', 'cpl', 'conversion_rate'], $e->metricSet('leads', 'website'));
        $this->assertSame(['spend', 'impressions', 'leads', 'cpl', 'conversion_rate'], $e->metricSet('leads'));
        foreach (['whatsapp', 'messenger', 'instagram_direct', 'lead_form', 'calls'] as $destination) {
            $this->assertSame(['spend', 'impressions', 'leads', 'cpl'], $e->metricSet('leads', $destination), $destination);
        }
    }

    public function test_a_messaging_report_offers_no_sales_figure_as_primary(): void
    {
        $set = $this->engine()->metricSet('leads', 'whatsapp');

        foreach (['revenue', 'roas', 'purchases', 'aov', 'add_to_cart'] as $banned) {
            $this->assertNotContains($banned, $set);
        }
    }

    public function test_a_set_the_engine_shipped_is_a_preset_and_an_owners_edit_is_not(): void
    {
        $e = $this->engine();

        $this->assertTrue($e->isPreset(['leads', 'cpl', 'conversion_rate', 'ctr', 'spend']), 'an old default was kept as if chosen');
        $this->assertTrue($e->isPreset(['spend', 'impressions', 'leads', 'cpl']));
        $this->assertFalse($e->isPreset(['spend', 'leads']), "an Owner's own selection was treated as a preset");
    }
}
