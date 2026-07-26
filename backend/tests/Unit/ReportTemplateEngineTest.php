<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Services\ReportTemplateEngine;
use PHPUnit\Framework\TestCase;

/**
 * The objective-driven report template is the single backend source of truth for which KPIs lead,
 * which are hidden, and the funnel shape — so an Awareness report never headlines ROAS, a Traffic
 * report never treats clicks as purchases, etc. (no scattered objective conditionals in React).
 */
final class ReportTemplateEngineTest extends TestCase
{
    private ReportTemplateEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ReportTemplateEngine;
    }

    public function test_sales_leads_with_roas_and_has_a_purchase_funnel(): void
    {
        $t = $this->engine->objectiveTemplate('sales');
        $this->assertSame('roas', $t['primary_kpi']);
        $this->assertContains('purchase', $t['funnel']);
        $this->assertSame('roas', $t['rank_metric']);
    }

    public function test_awareness_hides_roas_and_revenue_and_has_no_funnel(): void
    {
        $t = $this->engine->objectiveTemplate('awareness');
        $this->assertSame('cpm', $t['primary_kpi']);
        $this->assertContains('roas', $t['hidden_kpis']);
        $this->assertContains('revenue', $t['hidden_kpis']);
        $this->assertNull($t['funnel']);
    }

    public function test_traffic_leads_with_ctr_and_hides_revenue(): void
    {
        $t = $this->engine->objectiveTemplate('traffic');
        $this->assertSame('ctr', $t['primary_kpi']);
        $this->assertContains('revenue', $t['hidden_kpis']);
    }

    public function test_leads_uses_cpl_and_a_lead_funnel(): void
    {
        $t = $this->engine->objectiveTemplate('leads');
        $this->assertSame('cpl', $t['primary_kpi']);
        $this->assertContains('qualified_leads', $t['funnel']);
        $this->assertContains('roas', $t['hidden_kpis']);
    }

    public function test_video_uses_completion_rate(): void
    {
        $t = $this->engine->objectiveTemplate('video');
        $this->assertSame('completion_rate', $t['primary_kpi']);
        $this->assertContains('roas', $t['hidden_kpis']);
    }

    public function test_aliases_normalise(): void
    {
        // conversions→sales, reach→awareness, app_installs→app, video_views→video.
        $this->assertSame('roas', $this->engine->objectiveTemplate('conversions')['primary_kpi']);
        $this->assertSame('cpm', $this->engine->objectiveTemplate('reach')['primary_kpi']);
        $this->assertSame('cpa', $this->engine->objectiveTemplate('app_installs')['primary_kpi']);
        $this->assertSame('completion_rate', $this->engine->objectiveTemplate('video_views')['primary_kpi']);
    }

    public function test_unknown_objective_falls_back_to_custom(): void
    {
        $t = $this->engine->objectiveTemplate('nonsense');
        $this->assertSame('custom', $t['objective']);
        $this->assertSame([], $t['hidden_kpis']);
    }
}
