<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\DailyDigestMail;
use Tests\TestCase;

/**
 * EMAIL-DASH-VISUAL-001 / EMAIL-SAFE-CHARTS-001 — the dashboard email is a compact visual dashboard, not
 * prose, and every chart in it survives an email client: tables and cell bars, no images, no scripts.
 *
 * Asserted against the RENDERED HTML, which the ledger said was the missing half.
 */
final class DigestMailVisualTest extends TestCase
{
    private function digest(): array
    {
        return [
            'sendable' => true, 'reason' => null, 'date' => '2026-10-09', 'previous_date' => '2026-10-08',
            'totals' => ['projects' => 2, 'spend' => 12400.0, 'conversions' => 84.0, 'revenue' => 51000.0],
            'projects' => [[
                'project_id' => 'p1', 'project_name' => 'Nakheel — Sales',
                'totals' => ['spend' => 9400.0, 'conversions' => 62.0, 'impressions' => 240000.0, 'reach' => 0.0, 'cpa' => 151.6, 'roas' => 4.2],
                'reported' => ['spend' => true, 'conversions' => true, 'impressions' => true, 'reach' => false],
                'change' => ['spend' => 0.12, 'conversions' => -0.08, 'cpa' => 0.31, 'impressions' => null],
                'paths' => [
                    'awareness' => ['spend' => 4000.0, 'conversions' => 0.0, 'revenue' => 0.0, 'campaigns' => 2, 'cost_per_result' => null, 'roas' => null, 'headline_metrics' => []],
                    'traffic' => ['spend' => 0.0, 'conversions' => 0.0, 'revenue' => 0.0, 'campaigns' => 0, 'cost_per_result' => null, 'roas' => null, 'headline_metrics' => []],
                    'conversion' => ['spend' => 5400.0, 'conversions' => 62.0, 'revenue' => 39000.0, 'campaigns' => 3, 'cost_per_result' => 87.1, 'roas' => 7.2, 'headline_metrics' => []],
                ],
                'best_platform' => ['label' => 'meta', 'spend' => 3900.0, 'conversions' => 40.0, 'cpa' => 97.5, 'roas' => 5.1],
                'worst_platform' => ['label' => 'tiktok', 'spend' => 1200.0, 'conversions' => 3.0, 'cpa' => 400.0, 'roas' => 0.4],
                'best_campaign' => null, 'worst_campaign' => null, 'budget' => [],
                'freshness' => ['state' => 'fresh', 'last_sync_at' => '2026-10-10T04:10:00+00:00', 'sync_failed' => false, 'failing' => []],
            ]],
        ];
    }

    private function html(string $locale): string
    {
        return (new DailyDigestMail($this->digest(), $locale, 'Mohammed'))->render();
    }

    public function test_the_email_is_tiles_and_bars_before_it_is_sentences(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html($locale);

            // KPI tiles: at least three account-wide tiles, each a cell with a label and a bold figure.
            $this->assertGreaterThanOrEqual(3, substr_count($html, 'class="ch-kpi"'), "$locale: fewer than three KPI tiles");
            // At least one chart drawn as table-cell bars (spend share by path, funnel) — the email-safe chart.
            $this->assertGreaterThanOrEqual(1, substr_count($html, 'background-color:#eef2f1'), "$locale: no bar track");
            // Tables, not prose: more table cells than paragraphs by a wide margin.
            $cells = substr_count($html, '<td');
            $paragraphs = substr_count($html, '<p');
            $this->assertGreaterThan($paragraphs * 4, $cells, "$locale: $cells cells vs $paragraphs paragraphs — reads as prose");
            // No single run of text long enough to be a paragraph of prose: every text node between
            // two tags is a label, a figure or a short line.
            $visible = (string) preg_replace(['/<style\b[^>]*>.*?<\/style>/is', '/<!--.*?-->/s'], '', $html);
            preg_match_all('/>([^<]{120,})</u', $visible, $runs);
            foreach ($runs[1] as $run) {
                $run = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($run)));
                $this->assertLessThanOrEqual(240, mb_strlen($run), "$locale: a run of ".mb_strlen($run).' chars reads as prose: '.mb_substr($run, 0, 80));
            }
        }
    }

    public function test_every_chart_survives_an_email_client(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = $this->html($locale);

            $this->assertStringNotContainsString('<img', $html, "$locale: an image an email client may block");
            $this->assertStringNotContainsString('<svg', $html, "$locale: inline SVG, which Outlook drops");
            $this->assertStringNotContainsString('<script', $html);
            $this->assertStringNotContainsString('<canvas', $html);
            $this->assertDoesNotMatchRegularExpression('/url\(\s*[\'"]?https?:/i', $html, "$locale: a remote background");
            $this->assertStringNotContainsString('<link ', $html, "$locale: an external stylesheet");
            // Width is fixed for the 600 px email column, and the document declares its direction.
            $this->assertStringContainsString('width="100%"', $html);
            $this->assertMatchesRegularExpression('/<html[^>]*dir="(rtl|ltr)"/', $html);
        }
    }
}
