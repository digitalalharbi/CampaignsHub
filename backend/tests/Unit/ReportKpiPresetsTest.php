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

        $this->assertSame(['spend', 'impressions', 'reach', 'frequency', 'cpm'], $e->metricSet('awareness'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'ctr', 'cpc'], $e->metricSet('traffic'));
        $this->assertSame(['spend', 'impressions', 'engagements', 'engagement_rate'], $e->metricSet('engagement'));
        // The Owner's sales funnel (REPORT-OBJECTIVE-TEMPLATE-001): the cost per purchase is its own show/hide switch, not a mandatory card.
        $this->assertSame(['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], $e->metricSet('sales'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'installs', 'cpi'], $e->metricSet('app_installs'));
        $this->assertSame(['spend', 'impressions', 'clicks', 'leads'], $e->metricSet('leads'));
    }

    /** The Owner's Leads default is one set for every destination; a cost per lead is switched on by hand (REPORT-KPI-CONTRACT-001). */
    public function test_leads_have_one_default_whatever_the_destination(): void
    {
        $e = $this->engine();

        foreach ([null, 'website', 'whatsapp', 'messenger', 'instagram_direct', 'lead_form', 'calls'] as $destination) {
            $this->assertSame(['spend', 'impressions', 'clicks', 'leads'], $e->metricSet('leads', $destination), (string) $destination);
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
        $this->assertTrue($e->isPreset(['impressions', 'reach', 'frequency', 'cpm']), 'the previous awareness default is still recognised as shipped');
        $this->assertFalse($e->isPreset(['spend', 'leads']), "an Owner's own selection was treated as a preset");
    }
}
