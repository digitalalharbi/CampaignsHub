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
        $this->assertSame(['cover', 'recommendations'], array_slice($types, 0, 2));
        // 2 fixed + 4 slides per platform × 2 platforms = 10.
        $this->assertCount(10, $config['slides']);
        // No slide for an unconnected platform.
        $platforms = array_filter(array_column($config['slides'], 'platform'));
        $this->assertEmpty(array_diff($platforms, ['meta', 'snapchat']));
    }

    public function test_screenshot_slides_start_hidden(): void
    {
        $config = app(ReportTemplateEngine::class)->defaultConfig('awareness', ['tiktok']);
        $shot = collect($config['slides'])->firstWhere('type', 'platform_screenshot');
        $this->assertFalse($shot['visible']); // needs a manual upload before showing a client
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
