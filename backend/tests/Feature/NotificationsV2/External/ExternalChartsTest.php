<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\External\Charts\ChartImages;
use App\Domains\Notifications\External\Charts\ChartSvg;
use App\Domains\Notifications\External\Charts\ResvgCharts;
use App\Domains\Notifications\External\Design\DesignTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\NotificationsV2\Concerns\BuildsWorkspaces;
use Tests\Feature\NotificationsV2\External\Support\ExternalFixtures;
use Tests\TestCase;

/** Spec §11 / §16 «Charts»: same figures as the tables and the UI tokens, resvg PNG 2x, cached, budgets, fallback. */
final class ExternalChartsTest extends TestCase
{
    use BuildsWorkspaces;
    use ExternalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpExternal();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_chart_svg_uses_the_shared_tokens_and_the_message_figures(): void
    {
        $donut = ChartSvg::for(['kind' => 'donut', 'center' => '4,200.00 $', 'segments' => [['provider' => 'meta', 'label' => 'Meta', 'value' => 2500.0], ['provider' => 'snapchat', 'label' => 'Snapchat', 'value' => 1200.0]]], 'light');
        $this->assertNotNull($donut);
        $this->assertStringContainsString(DesignTokens::platform('meta'), $donut['svg']);
        $this->assertStringContainsString(DesignTokens::platform('snapchat'), $donut['svg']);
        $this->assertStringContainsString('4,200.00 $', $donut['svg']);
        $dark = ChartSvg::for(['kind' => 'ring', 'ratio' => 0.42, 'label' => '42%'], 'dark');
        $this->assertStringContainsString(DesignTokens::color('brand-600', 'dark'), (string) $dark['svg']);
        $this->assertNull(ChartSvg::for(['kind' => 'trend', 'values' => [5.0]], 'light')); // one point is not a trend
    }

    public function test_resvg_renders_inline_cid_png_at_2x_with_dark_alternates_counts_and_caches(): void
    {
        if (! ResvgCharts::available()) {
            $this->markTestSkipped('@resvg/resvg-js not installed beside the require base');
        }
        // NOTIF-V2 (2026-11-15): the approved templates draw with table cells, so no message embeds these images any more. The renderer
        // stays (resvg at 2x, light + dark, cached, within the size budget) for the surfaces that ask for a real chart image.
        $this->app->scoped(ChartImages::class, ResvgCharts::class);
        $out = app(ChartImages::class)->render($this->tenant->id, 'scope-a', [['key' => 'ring', 'kind' => 'ring', 'rtl' => true, 'ratio' => 0.42, 'label' => '42%']]);
        $this->assertNotNull($out['ring']);
        $this->assertSame("\x89PNG", substr($out['ring']['light'], 0, 4));
        $this->assertSame("\x89PNG", substr($out['ring']['dark'], 0, 4));
        $this->assertLessThan(400 * 1024, strlen($out['ring']['light']) + strlen($out['ring']['dark']));

        // Cached per project + period + type + theme: the second recipient's identical charts render nothing new.
        $started = microtime(true);
        $again = app(ChartImages::class)->render($this->tenant->id, implode(':', [$this->project->id, '2026-11-01', '2026-11-14', 'digest.periodic', 'USD', 'ar']), [['key' => 'ring', 'kind' => 'ring', 'rtl' => true, 'ratio' => 0.42, 'label' => '42%']]);
        $this->assertNotNull($again['ring']);
        $this->assertLessThan(0.3, microtime(true) - $started);
    }
}
