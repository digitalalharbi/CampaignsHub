<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Analytics\ObjectiveMetricFamilies;
use App\Domains\Reports\Analytics\ObjectiveReportAnalytics;
use App\Domains\Reports\Support\CreativeVisibility;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — the objective section's arithmetic, with no database.
 *
 * Every figure here is chosen so a wrong rule cannot hide in rounding: an averaged ratio, a blended
 * cross-family figure, a ranking over three impressions or a «0» for a figure nobody sent each
 * produces a different number from the one asserted.
 */
final class ObjectiveReportAnalyticsTest extends TestCase
{
    private function from(): Carbon
    {
        return Carbon::parse('2026-07-01');
    }

    private function to(): Carbon
    {
        return Carbon::parse('2026-07-03');
    }

    /** A sums row, with every figure given marked as reported. @param array<string,float|int|null> $figures */
    private function row(string $objective, string $provider, array $figures, array $withheld = []): array
    {
        $row = ['objective' => $objective, 'provider' => $provider];

        foreach ($figures as $key => $value) {
            $row[$key] = $value;
            $row["{$key}_rows"] = 1;
        }
        foreach ($withheld as $key) {
            $row["{$key}_withheld"] = 1;
            $row["{$key}_rows"] = 1;
        }

        return $row;
    }

    private function build(array $rows, array $days = [], array $content = []): array
    {
        return (new ObjectiveReportAnalytics)->build($rows, $days, $content, $this->from(), $this->to());
    }

    /** @return array<string,mixed> */
    private function family(array $section, string $family): array
    {
        foreach ($section['families'] as $block) {
            if ($block['family'] === $family) {
                return $block;
            }
        }

        $this->fail("no {$family} block");
    }

    /** @return array<string,mixed>|null */
    private function kpi(array $block, string $key): ?array
    {
        foreach ($block['kpis'] as $kpi) {
            if ($kpi['key'] === $key) {
                return $kpi;
            }
        }

        return null;
    }

    /** @return iterable<string, array{string, string, array<string,float>, array<string,float>, list<string>}> */
    public static function objectives(): iterable
    {
        yield 'awareness' => ['reach', 'awareness', ['spend' => 500, 'impressions' => 100_000, 'reach' => 40_000],
            ['reach' => 40_000, 'impressions' => 100_000, 'frequency' => 2.5, 'cpm' => 5.0], ['cpa', 'roas', 'cpl', 'ctr']];
        yield 'traffic' => ['landing_page_views', 'traffic', ['spend' => 300, 'impressions' => 60_000, 'clicks' => 1_200, 'landing_page_views' => 600],
            ['clicks' => 1_200, 'ctr' => 0.02, 'cpc' => 0.25, 'landing_page_views' => 600, 'cost_per_lpv' => 0.5], ['cpa', 'roas', 'cpm', 'reach']];
        yield 'leads' => ['leads', 'leads', ['spend' => 1_000, 'leads' => 40],
            ['leads' => 40, 'cpl' => 25.0], ['roas', 'revenue', 'cpa', 'cpm']];
        yield 'sales' => ['purchases', 'sales', ['spend' => 2_000, 'conversions' => 50, 'revenue' => 10_000],
            ['conversions' => 50, 'revenue' => 10_000, 'cpa' => 40.0, 'roas' => 5.0], ['cpl', 'cpm', 'reach', 'ctr']];
        yield 'engagement' => ['engagement', 'engagement', ['spend' => 100, 'impressions' => 20_000, 'engagements' => 400],
            ['engagements' => 400, 'engagement_rate' => 0.02, 'cpe' => 0.25], ['cpa', 'roas']];
        yield 'app' => ['app_installs', 'app', ['spend' => 900, 'installs' => 300],
            ['installs' => 300, 'cpi' => 3.0], ['roas', 'cpl', 'cpa']];
    }

    /**
     * @param  array<string,float>  $figures
     * @param  array<string,float>  $expected
     * @param  list<string>  $inapplicable
     */
    #[Test]
    #[DataProvider('objectives')]
    public function each_objective_shows_its_own_family_and_nothing_else(string $objective, string $family, array $figures, array $expected, array $inapplicable): void
    {
        $block = $this->family($this->build([$this->row($objective, 'meta', $figures)]), $family);

        $this->assertSame(['spend', ...ObjectiveMetricFamilies::kpis($family)], array_column($block['kpis'], 'key'));

        foreach ($expected as $key => $value) {
            $kpi = $this->kpi($block, $key);
            $this->assertSame('reported', $kpi['state'], $key);
            $this->assertEqualsWithDelta($value, $kpi['value'], 0.0001, $key);
        }

        foreach ($inapplicable as $key) {
            $this->assertNull($this->kpi($block, $key), "{$key} is not a {$family} figure and must not be in its block");
            $this->assertSame('inapplicable', ObjectiveMetricFamilies::applicability($family, $key));
        }
    }

    #[Test]
    public function unavailable_zero_and_inapplicable_are_three_different_answers(): void
    {
        // Reach NOT reported; impressions reported; leads reported as a measured zero.
        $section = $this->build([
            $this->row('awareness', 'x', ['spend' => 200, 'impressions' => 50_000]),
            $this->row('leads', 'meta', ['spend' => 300, 'leads' => 0]),
        ]);

        $awareness = $this->family($section, 'awareness');
        $this->assertSame(['value' => null, 'state' => 'unavailable', 'reason' => 'not_reported'], array_intersect_key($this->kpi($awareness, 'reach'), array_flip(['value', 'state', 'reason'])));
        // Frequency divides by reach, so it is unavailable for the same reason — never 0, never ∞.
        $this->assertSame('not_reported', $this->kpi($awareness, 'frequency')['reason']);
        $this->assertSame(4.0, $this->kpi($awareness, 'cpm')['value']);

        $leads = $this->family($section, 'leads');
        $this->assertSame(0.0, $this->kpi($leads, 'leads')['value'], 'a reported zero is a measured 0');
        $this->assertSame('reported', $this->kpi($leads, 'leads')['state']);
        $this->assertSame(['state' => 'unavailable', 'reason' => 'zero_denominator'], array_intersect_key($this->kpi($leads, 'cpl'), array_flip(['state', 'reason'])));

        $this->assertNull($this->kpi($leads, 'roas'), 'ROAS is inapplicable to leads and is simply absent');
    }

    #[Test]
    public function a_ratio_rests_only_on_the_platforms_that_reported_both_of_its_parts(): void
    {
        $block = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 20, 'revenue' => 5_000]),
            $this->row('sales', 'tiktok', ['spend' => 1_000, 'conversions' => 10]),
        ]), 'sales');

        // Diluted by TikTok's unmeasured return it would read 2.5×; the truth is Meta's 5× over Meta.
        $this->assertSame(5.0, $this->kpi($block, 'roas')['value']);
        $this->assertSame(['tiktok'], $this->kpi($block, 'roas')['not_reported_by']);
        $this->assertSame([], $this->kpi($block, 'cpa')['not_reported_by']);
        $this->assertSame(round(2_000 / 30, 2), $this->kpi($block, 'cpa')['value']);
    }

    #[Test]
    public function withheld_money_is_unavailable_not_a_smaller_number(): void
    {
        $block = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 10, 'revenue' => 4_000]),
            $this->row('sales', 'snapchat', ['conversions' => 5], withheld: ['spend']),
        ]), 'sales');

        $this->assertSame('money_not_converted', $this->kpi($block, 'spend')['reason']);
        // CPA rests on the platform whose spend WAS converted, and names the one that was not.
        $this->assertSame(100.0, $this->kpi($block, 'cpa')['value']);
        $this->assertSame(['snapchat'], $this->kpi($block, 'cpa')['not_reported_by']);
        $this->assertSame(15.0, $this->kpi($block, 'conversions')['value']);
    }

    #[Test]
    public function ratios_are_rebuilt_from_summed_numerators_and_denominators_never_averaged(): void
    {
        // Meta CPM 10 on 100k impressions, TikTok CPM 100 on 1k. Averaged: 55. Summed: 1100/101k ×1000.
        $block = $this->family($this->build([
            $this->row('awareness', 'meta', ['spend' => 1_000, 'impressions' => 100_000]),
            $this->row('awareness', 'tiktok', ['spend' => 100, 'impressions' => 1_000]),
        ]), 'awareness');

        $this->assertEqualsWithDelta(10.89, $this->kpi($block, 'cpm')['value'], 0.001);
    }

    #[Test]
    public function a_mixed_programme_gets_one_block_per_family_and_no_blended_kpi(): void
    {
        $section = $this->build([
            $this->row('awareness', 'meta', ['spend' => 4_000, 'impressions' => 2_000_000]),
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 50, 'revenue' => 10_000]),
        ]);

        $this->assertTrue($section['mixed']);
        $this->assertFalse($section['cross_family_blend']);
        $this->assertSame(['awareness', 'sales'], array_column($section['families'], 'family'));
        // The sales CPA is sales spend over sales orders — the awareness 4000 never reaches it.
        $this->assertSame(20.0, $this->kpi($this->family($section, 'sales'), 'cpa')['value']);
        $this->assertSame(4_000.0, $this->kpi($this->family($section, 'awareness'), 'spend')['value']);
        $this->assertArrayNotHasKey('kpis', $section, 'no section-level KPI may blend families');
    }

    #[Test]
    public function an_unclassified_objective_has_no_block_and_says_it_was_there(): void
    {
        $section = $this->build([$this->row('other', 'meta', ['spend' => 50, 'impressions' => 9_000])]);

        $this->assertSame([], $section['families']);
        $this->assertTrue($section['unclassified_present']);
    }

    #[Test]
    public function platforms_are_ranked_inside_the_family_above_the_minimum_volume_only(): void
    {
        $block = $this->family($this->build([
            $this->row('awareness', 'meta', ['spend' => 1_000, 'impressions' => 200_000]),   // CPM 5
            $this->row('awareness', 'tiktok', ['spend' => 1_500, 'impressions' => 100_000]), // CPM 15
            $this->row('awareness', 'x', ['spend' => 0.01, 'impressions' => 3]),             // CPM 3.33 on 3 impressions
        ]), 'awareness');

        $ranking = $block['platform_ranking'];
        $this->assertSame('cpm', $ranking['metric']);
        $this->assertSame('meta', $ranking['best']['provider'], 'three impressions is never best');
        $this->assertSame('tiktok', $ranking['weakest']['provider']);
        $this->assertSame(2, $ranking['eligible']);
        $this->assertSame(3, $ranking['candidates']);
    }

    #[Test]
    public function too_little_data_is_no_ranking_rather_than_a_guess(): void
    {
        $one = $this->family($this->build([
            $this->row('leads', 'meta', ['spend' => 500, 'leads' => 20]),
        ]), 'leads')['platform_ranking'];
        $this->assertNull($one['best']);
        $this->assertNull($one['weakest']);
        $this->assertSame('only_one_candidate', $one['reason']);

        $thin = $this->family($this->build([
            $this->row('leads', 'meta', ['spend' => 500, 'leads' => 20]),
            $this->row('leads', 'snapchat', ['spend' => 40, 'leads' => 2]),
        ]), 'leads')['platform_ranking'];
        $this->assertSame('cpl', $thin['metric']);
        $this->assertNull($thin['best']);
        $this->assertSame('too_few_above_minimum_volume', $thin['reason']);
    }

    #[Test]
    public function two_ends_a_rounding_apart_are_not_a_strongest_and_a_weakest(): void
    {
        $ranking = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 20]),      // 50.00
            $this->row('sales', 'snapchat', ['spend' => 999.8, 'conversions' => 20]),  // 49.99
        ]), 'sales')['platform_ranking'];

        $this->assertNull($ranking['best']);
        $this->assertSame('indistinguishable', $ranking['reason']);
    }

    #[Test]
    public function a_volume_only_family_is_not_ranked_by_budget(): void
    {
        // Reach reported, but no spend-per-impression: the canonical fallback is a volume — refused.
        $ranking = $this->family($this->build([
            $this->row('awareness', 'meta', ['spend' => 100, 'reach' => 90_000]),
            $this->row('awareness', 'tiktok', ['spend' => 100, 'reach' => 10_000]),
        ]), 'awareness')['platform_ranking'];

        $this->assertNull($ranking['best']);
        $this->assertSame('no_defensible_metric', $ranking['reason']);
    }

    #[Test]
    public function sales_platforms_rank_on_roas_and_fall_back_to_cpa_when_revenue_is_not_reported(): void
    {
        $withRevenue = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 20, 'revenue' => 5_000]),
            $this->row('sales', 'snapchat', ['spend' => 1_000, 'conversions' => 30, 'revenue' => 2_000]),
        ]), 'sales')['platform_ranking'];
        $this->assertSame('roas', $withRevenue['metric']);
        $this->assertSame('meta', $withRevenue['best']['provider']);

        $noRevenue = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 20]),
            $this->row('sales', 'snapchat', ['spend' => 1_000, 'conversions' => 40]),
        ]), 'sales')['platform_ranking'];
        $this->assertSame('cpa', $noRevenue['metric']);
        $this->assertSame('snapchat', $noRevenue['best']['provider']);
        $this->assertSame('meta', $noRevenue['weakest']['provider']);
    }

    #[Test]
    public function a_primary_only_one_platform_reports_gives_way_to_the_next_metric_both_report(): void
    {
        // Only Meta sends revenue, so ROAS compares nothing; both send orders, so CPA compares two.
        $ranking = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1_000, 'conversions' => 20, 'revenue' => 5_000]),
            $this->row('sales', 'snapchat', ['spend' => 1_000, 'conversions' => 40]),
        ]), 'sales')['platform_ranking'];

        $this->assertSame('cpa', $ranking['metric']);
        $this->assertSame('snapchat', $ranking['best']['provider']);
        $this->assertSame(2, $ranking['eligible']);
    }

    #[Test]
    public function content_is_ranked_per_family_with_the_same_threshold_and_never_names_a_campaign(): void
    {
        $content = [
            ['name' => 'Video A', 'provider' => 'tiktok', 'format' => 'video', 'objective' => 'traffic', 'campaign_name' => 'Secret plan',
                'metrics' => ['spend' => 100, 'impressions' => 50_000, 'clicks' => 1_000]],   // CTR 2%
            ['name' => 'Image B', 'provider' => 'meta', 'format' => 'image', 'objective' => 'traffic',
                'metrics' => ['spend' => 100, 'impressions' => 40_000, 'clicks' => 200]],     // CTR 0.5%
            ['name' => 'Tiny C', 'provider' => 'meta', 'format' => 'image', 'objective' => 'traffic',
                'metrics' => ['spend' => 1, 'impressions' => 3, 'clicks' => 1]],              // CTR 33% on 3
            ['name' => 'Brand D', 'provider' => 'meta', 'format' => 'image', 'objective' => 'awareness',
                'metrics' => ['spend' => 100, 'impressions' => 90_000]],
        ];

        $block = $this->family($this->build([
            $this->row('traffic', 'meta', ['spend' => 201, 'impressions' => 40_003, 'clicks' => 201]),
            $this->row('traffic', 'tiktok', ['spend' => 100, 'impressions' => 50_000, 'clicks' => 1_000]),
        ], content: $content), 'traffic');

        $ranking = $block['content_ranking'];
        $this->assertSame('ctr', $ranking['metric']);
        $this->assertSame('Video A', $ranking['best']['name']);
        $this->assertSame('Image B', $ranking['weakest']['name']);
        $this->assertSame(3, $ranking['candidates'], 'the awareness creative is not a traffic candidate');
        $this->assertStringNotContainsString('Secret plan', json_encode($block, JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('campaign_name', $ranking['best']);
    }

    #[Test]
    public function contribution_is_each_platforms_share_of_the_family_outcome(): void
    {
        $block = $this->family($this->build([
            $this->row('leads', 'meta', ['spend' => 600, 'leads' => 30]),
            $this->row('leads', 'snapchat', ['spend' => 400, 'leads' => 10]),
        ]), 'leads');

        $this->assertSame('leads', $block['contribution']['outcome']);
        $this->assertSame([['provider' => 'meta', 'value' => 30.0, 'share' => 0.75], ['provider' => 'snapchat', 'value' => 10.0, 'share' => 0.25]], $block['contribution']['rows']);
        $shares = array_column($block['platforms'], 'spend_share', 'provider');
        $this->assertSame(0.6, $shares['meta']);
    }

    #[Test]
    public function sales_contribution_uses_revenue_when_reported_and_orders_otherwise(): void
    {
        $withRevenue = $this->family($this->build([$this->row('sales', 'meta', ['spend' => 1, 'conversions' => 1, 'revenue' => 10])]), 'sales');
        $this->assertSame('revenue', $withRevenue['contribution']['outcome']);

        $orders = $this->family($this->build([$this->row('sales', 'meta', ['spend' => 1, 'conversions' => 1])]), 'sales');
        $this->assertSame('conversions', $orders['contribution']['outcome']);

        // Revenue from one platform only: the share is of orders, which both reported.
        $partial = $this->family($this->build([
            $this->row('sales', 'meta', ['spend' => 1, 'conversions' => 3, 'revenue' => 10]),
            $this->row('sales', 'snapchat', ['spend' => 1, 'conversions' => 1]),
        ]), 'sales');
        $this->assertSame('conversions', $partial['contribution']['outcome']);
        $this->assertSame([0.75, 0.25], array_column($partial['contribution']['rows'], 'share'));
    }

    #[Test]
    public function the_trend_covers_every_day_and_derives_each_day_from_its_own_sums(): void
    {
        $days = [
            ['objective' => 'awareness', 'date' => '2026-07-01', 'spend' => 10, 'spend_rows' => 1, 'impressions' => 1_000, 'impressions_rows' => 1],
            ['objective' => 'awareness', 'date' => '2026-07-03', 'spend' => 30, 'spend_rows' => 1, 'impressions' => 2_000, 'impressions_rows' => 1],
        ];

        $trend = $this->family($this->build([
            $this->row('awareness', 'meta', ['spend' => 40, 'impressions' => 3_000]),
        ], $days), 'awareness')['trend'];

        $this->assertSame('cpm', $trend['metric']);
        $this->assertCount(3, $trend['points']);
        $this->assertSame(10.0, $trend['points'][0]['value']);
        $this->assertFalse($trend['points'][1]['reported']);
        $this->assertNull($trend['points'][1]['value'], 'a day with no row is a gap, not a zero');
        $this->assertSame(15.0, $trend['points'][2]['value']);
    }

    #[Test]
    public function a_link_hiding_spend_loses_every_cost_figure_and_the_rankings_built_on_them(): void
    {
        $section = $this->build([
            $this->row('awareness', 'meta', ['spend' => 1_000, 'impressions' => 200_000]),
            $this->row('awareness', 'tiktok', ['spend' => 1_500, 'impressions' => 100_000]),
        ]);

        $redacted = ObjectiveReportAnalytics::redact($section, CreativeVisibility::COST_METRICS);
        $block = $this->family($redacted, 'awareness');

        $this->assertNull($this->kpi($block, 'spend'));
        $this->assertNull($this->kpi($block, 'cpm'));
        $this->assertNotNull($this->kpi($block, 'impressions'));
        $this->assertNull($block['platform_ranking']['best']);
        $this->assertSame('hidden_by_link', $block['platform_ranking']['reason']);
        $this->assertNull($block['platforms'][0]['spend_share']);
        $json = json_encode($block);
        $this->assertStringNotContainsString('"cpm"', $json);
    }
}
