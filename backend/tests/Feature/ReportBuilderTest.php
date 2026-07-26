<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Reports\Services\CreativeRankingService;
use App\Domains\Reports\Services\ReportTemplateEngine;
use Tests\TestCase;

/** Report intelligence: objective-driven slide templates + explained creative ranking (pure services). */
final class ReportBuilderTest extends TestCase
{
    public function test_template_builds_slides_only_for_connected_platforms(): void
    {
        $config = app(ReportTemplateEngine::class)->defaultConfig('sales', ['meta', 'snapchat']);

        $this->assertSame('sales', $config['objective']);
        $this->assertContains('spend', $config['metric_set']);
        $this->assertContains('roas', $config['metric_set']);

        $types = array_column($config['slides'], 'type');
        $this->assertSame(['cover', 'recommendations', 'executive_summary'], array_slice($types, 0, 3));
        // 3 fixed (cover + recommendations + executive_summary) + 1 rich slide per platform × 2 platforms
        // + closing (platform_comparison, funnel since sales, budget, next_steps) = 3 + 2 + 4 = 9.
        $this->assertCount(9, $config['slides']);
        $this->assertContains('platform_comparison', $types);
        $this->assertContains('funnel', $types);
        $this->assertContains('budget', $types);
        $this->assertContains('next_steps', $types);
        // Exactly one performance slide per connected platform, and none for unconnected platforms.
        $platforms = array_filter(array_column($config['slides'], 'platform'));
        $this->assertEqualsCanonicalizing(['meta', 'snapchat'], array_values($platforms));
    }

    public function test_screenshot_and_standalone_platform_slides_not_auto_generated(): void
    {
        // Screenshots need a manual upload and notes/creatives render sparse standalone, so the default
        // layout emits exactly one rich performance slide per platform — the rest are builder-only.
        $config = app(ReportTemplateEngine::class)->defaultConfig('awareness', ['tiktok']);
        $types = array_column($config['slides'], 'type');
        $this->assertNotContains('platform_screenshot', $types);
        $this->assertNotContains('platform_notes', $types);
        $this->assertNotContains('top_creatives', $types);
        $this->assertContains('platform_performance', $types);
    }

    public function test_platform_order_follows_convention(): void
    {
        $config = app(ReportTemplateEngine::class)->defaultConfig('sales', ['google', 'snapchat', 'meta']);
        $this->assertSame(['snapchat', 'meta', 'google'], $config['platform_order']);
    }

    public function test_sales_ranking_prefers_roas_with_reason(): void
    {
        $ranked = app(CreativeRankingService::class)->rank('sales', [
            ['campaign_name' => 'Low', 'spend' => 100, 'roas' => 2.0, 'cpa' => 50],
            ['campaign_name' => 'High', 'spend' => 100, 'roas' => 8.0, 'cpa' => 20],
        ]);
        $this->assertSame('High', $ranked[0]['campaign_name']); // highest ROAS first
        $this->assertStringContainsString('ROAS', $ranked[0]['reason']); // reason is explicit, not opaque
    }

    public function test_leads_ranking_prefers_low_cpa(): void
    {
        $ranked = app(CreativeRankingService::class)->rank('leads', [
            ['campaign_name' => 'Expensive', 'spend' => 100, 'cpa' => 80],
            ['campaign_name' => 'Cheap', 'spend' => 100, 'cpa' => 25],
        ]);
        $this->assertSame('Cheap', $ranked[0]['campaign_name']); // lowest CPA first
        $this->assertStringContainsString('CPA', $ranked[0]['reason']);
    }
}
