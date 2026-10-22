<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Charts\MailChart;
use App\Domains\Notifications\Mail\DailyDigestMail;
use App\Domains\Notifications\Support\MailGallery;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * EMAIL-DECISION-REPORT-001 — static chart images for email: drawn server-side, served by opaque token,
 * never a figure in a URL, never a broken image, always a readable fallback beside them.
 */
final class MailChartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! MailChart::available()) {
            $this->markTestSkipped('GD is not loaded here; the mailable embeds no image in that case.');
        }
    }

    public function test_a_trend_and_a_donut_render_as_png_and_a_flat_or_empty_series_is_refused(): void
    {
        $trend = MailChart::render(['kind' => 'trend', 'values' => [5210.0, 6480.5, 5930.0, 7102.2], 'width' => 320, 'height' => 64]);
        $this->assertSame("\x89PNG", substr($trend, 0, 4));
        [$w, $h] = getimagesizefromstring($trend);
        $this->assertSame([640, 128], [$w, $h], 'drawn at twice the stated size for high-density screens');

        $donut = MailChart::render(['kind' => 'donut', 'segments' => [['value' => 60.0], ['value' => 40.0]], 'size' => 112]);
        $this->assertSame("\x89PNG", substr($donut, 0, 4));

        $this->assertFalse(MailChart::drawable(['kind' => 'trend', 'values' => [120.0, 120.0, 120.0]]), 'a flat line is not a trend');
        $this->assertFalse(MailChart::drawable(['kind' => 'trend', 'values' => [120.0]]));
        $this->assertFalse(MailChart::drawable(['kind' => 'donut', 'segments' => [['value' => 0.0]]]));
        $this->assertNull(MailChart::store(['kind' => 'trend', 'values' => [1.0, 1.0]]), 'nothing drawable, nothing stored');
    }

    /** A day nobody reported is a hole in the chart: nothing is drawn in its column, and the alt says so. */
    public function test_a_missing_day_leaves_a_gap_in_the_trend_rather_than_a_line_through_it(): void
    {
        // Four days, the second unreported: points sit at 0, 1/3, 2/3 and 1 of the width.
        $png = MailChart::render(['kind' => 'trend', 'values' => [10.0, null, 20.0, 30.0], 'width' => 300, 'height' => 60]);
        $img = imagecreatefromstring($png);
        $this->assertNotFalse($img);
        $w = imagesx($img);
        $h = imagesy($img);
        $opaque = static function (int $x) use ($img, $h): int {
            $n = 0;
            for ($y = 0; $y < $h; $y++) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) < 127) {
                    $n++;
                }
            }

            return $n;
        };
        // In the hole (the column of the unreported day) nothing is drawn; inside the measured run the area and the line are.
        $this->assertSame(0, $opaque((int) round($w / 3)), 'the unreported day was drawn through');
        $this->assertGreaterThan(0, $opaque((int) round($w * 0.83)), 'the measured run is drawn');

        $this->assertTrue(MailChart::drawable(['kind' => 'trend', 'values' => [10.0, null, 20.0, 30.0]]));
        $this->assertFalse(MailChart::drawable(['kind' => 'trend', 'values' => [10.0, null, null, 30.0]]), 'more than a third missing is refused, like the dashboard');
    }

    public function test_the_route_serves_a_stored_token_as_png_and_answers_404_for_the_rest(): void
    {
        $token = MailChart::store(['kind' => 'trend', 'values' => [10.0, 20.0, 15.0]]);
        $this->assertNotNull($token);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{32}$/', $token);
        $this->assertSame(['kind' => 'trend', 'values' => [10.0, 20.0, 15.0]], MailChart::spec($token));

        $this->get("/mail/charts/{$token}.png")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get('/mail/charts/'.str_repeat('f', 32).'.png')->assertNotFound();
        $this->get('/mail/charts/not-a-token.png')->assertNotFound();
        $this->get('/mail/charts/'.strtoupper($token).'.png')->assertNotFound();
        $this->get('/mail/charts/'.$token.'/extra.png')->assertNotFound();

        Cache::forget('mail-chart:'.$token);
        $this->get("/mail/charts/{$token}.png")->assertNotFound();
    }

    /** The digest embeds both charts, each with its figures in the alt and a caption or legend beside it — and no figure in the URL. */
    public function test_the_digest_embeds_the_trend_and_the_donut_with_their_fallbacks(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = (string) MailGallery::render('digest-daily', $locale);

            $this->assertStringContainsString('data-block="chart-trend"', $html, "[$locale] trend");
            $this->assertStringContainsString('data-block="chart-donut"', $html, "[$locale] donut");
            $this->assertMatchesRegularExpression('#<img src="[^"]+/mail/charts/[a-z0-9]{32}\.png" width="320" height="64" alt="[^"]+"#', $html);
            $this->assertMatchesRegularExpression('#<img src="[^"]+/mail/charts/[a-z0-9]{32}\.png" width="112" height="112" alt="[^"]+"#', $html);
            // The legend is the fallback: every platform with its share, as text.
            $this->assertStringContainsString('44%', $html, "[$locale] meta's share in the legend");
            $this->assertStringNotContainsString('18240', $html, "[$locale] no figure travels in a chart URL");
        }
    }

    /**
     * Owner 2026-10-22 — a day nobody reported is neither drawn nor mentioned.
     *
     * The trend is built from the reported days only, its caption names the first and last REPORTED
     * day, and no wording about gaps or missing days reaches the email. Nothing is interpolated: the
     * stored specification holds exactly the reported values, in order.
     */
    public function test_an_unreported_day_is_left_out_of_the_trend_and_never_mentioned(): void
    {
        $digest = MailGallery::digest(weekly: true);
        $digest['totals']['series'] = [
            ['date' => '2026-07-31', 'spend' => 5000.0],
            ['date' => '2026-08-01', 'spend' => null],
            ['date' => '2026-08-02', 'spend' => 6100.0],
            ['date' => '2026-08-03', 'spend' => null],
            ['date' => '2026-08-04', 'spend' => 5900.0],
            ['date' => '2026-08-05', 'spend' => null],
        ];
        foreach (['ar', 'en'] as $locale) {
            $html = (new DailyDigestMail($digest, $locale, 'Mohammed', 'weekly'))->render();
            $this->assertStringContainsString('data-block="chart-trend"', $html);
            $this->assertStringContainsString('2026-07-31 → 2026-08-04', $html, "[$locale] the caption spans the reported days only");
            $this->assertStringNotContainsString('2026-08-05', $html, "[$locale] an unreported last day is not named");
            foreach (['الفجوات', 'لم تُبلَّغ', 'gaps', 'unreported', 'missing'] as $word) {
                $this->assertStringNotContainsString($word, $html, "[$locale] the email never says data is missing");
            }
            preg_match('#/mail/charts/([a-z0-9]{32})\.png" width="320"#', $html, $m);
            $this->assertSame([5000.0, 6100.0, 5900.0], MailChart::spec($m[1])['values'], 'the stored series is the reported days, in order, with nothing between them');
        }
    }
}
