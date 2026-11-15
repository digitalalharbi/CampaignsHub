<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\External;

use App\Domains\Notifications\External\Charts\ChartImages;
use App\Domains\Notifications\External\Charts\ChartSvg;
use App\Domains\Notifications\External\Charts\ResvgCharts;
use App\Domains\Notifications\External\Design\DesignTokens;
use App\Domains\Notifications\External\Digest\DigestDataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $donut = ChartSvg::for(['kind' => 'donut', 'center' => '4,200 $', 'segments' => [['provider' => 'meta', 'label' => 'Meta', 'value' => 2500.0], ['provider' => 'snapchat', 'label' => 'Snapchat', 'value' => 1200.0]]], 'light');
        $this->assertNotNull($donut);
        $this->assertStringContainsString(DesignTokens::platform('meta'), $donut['svg']);
        $this->assertStringContainsString(DesignTokens::platform('snapchat'), $donut['svg']);
        $this->assertStringContainsString('4,200 $', $donut['svg']);
        $dark = ChartSvg::for(['kind' => 'ring', 'ratio' => 0.42, 'label' => '42%'], 'dark');
        $this->assertStringContainsString(DesignTokens::color('brand-600', 'dark'), (string) $dark['svg']);
        $this->assertNull(ChartSvg::for(['kind' => 'trend', 'values' => [5.0]], 'light')); // one point is not a trend
    }

    public function test_resvg_renders_inline_cid_png_at_2x_with_dark_alternates_counts_and_caches(): void
    {
        if (! ResvgCharts::available()) {
            $this->markTestSkipped('@resvg/resvg-js not installed beside the require base');
        }
        $this->app->forgetScopedInstances();
        $this->app->scoped(ChartImages::class, ResvgCharts::class);
        $this->app->instance(DigestDataSource::class, $this->data); // forgetting scoped instances dropped the fake
        [, $s] = $this->subscribe('finance@client.test', 'finance', [$this->project->id]);
        $this->raise('digest.periodic', $this->project->id, $s);
        $this->drain();

        $mail = $this->sent()[0]['email'];
        $html = (string) $mail->getHtmlBody();
        foreach (['donut', 'trend', 'ring'] as $k) {
            $this->assertStringContainsString('src="cid:'.$k.'"', $html);
            $this->assertStringContainsString('src="cid:'.$k.'-dark"', $html);
        }
        $this->assertStringContainsString('alt="توزيع الإنفاق حسب المنصة: Meta 2,500.00 $', $html); // alt text with the numbers
        $parts = $mail->getAttachments();
        $this->assertCount(8, $parts); // donut, trend, ring and the lock glyph, each light + dark
        $bytes = 0;
        foreach ($parts as $part) {
            $png = $part->getBody();
            $bytes += strlen($png);
            $this->assertSame("\x89PNG", substr($png, 0, 4));
        }
        $this->assertLessThan(400 * 1024, $bytes);
        $this->assertLessThan(100 * 1024, strlen($html));
        $widths = array_map(static fn ($p): int => (int) getimagesizefromstring($p->getBody())[0], $parts);
        $this->assertContains(ChartSvg::WIDTH * 2, $widths); // the trend at 2x
        $this->assertContains(32, $widths);                  // the 16px lock at 2x
        $this->assertSame(3, DB::table('usage_counters')->where('tenant_id', $this->tenant->id)->where('metric', 'notif.charts_generated')->value('count'));

        // Cached per project + period + type + theme: the second recipient's identical charts render nothing new.
        $started = microtime(true);
        $again = app(ChartImages::class)->render($this->tenant->id, implode(':', [$this->project->id, '2026-11-01', '2026-11-14', 'digest.periodic', 'USD', 'ar']), [['key' => 'ring', 'kind' => 'ring', 'rtl' => true, 'ratio' => 0.42, 'label' => '42%']]);
        $this->assertNotNull($again['ring']);
        $this->assertLessThan(0.3, microtime(true) - $started);
    }
}
