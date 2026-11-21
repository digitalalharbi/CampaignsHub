<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Measurement\Ga4Visibility;
use PHPUnit\Framework\TestCase;

final class Ga4VisibilityTest extends TestCase
{
    /** @return array<string, mixed> */
    private function sections(): array
    {
        return [
            'ga4_overview' => [
                'kpis' => [['key' => 'sessions', 'current' => 1000], ['key' => 'revenue', 'current' => 4000], ['key' => 'aov', 'current' => 200], ['key' => 'bounce_rate', 'current' => 0.4]],
                'series' => [['date' => '2026-10-02', 'sessions' => 100, 'purchases' => 4, 'revenue' => 800, 'revenue_usd' => 200]],
            ],
            'ga4_acquisition' => [
                'channels' => [['key' => 'Paid Social', 'sessions' => 90, 'purchases' => 3, 'revenue' => 700, 'revenue_usd' => 175, 'share' => 0.9]],
                'devices' => [['key' => 'mobile', 'sessions' => 80, 'purchases' => 2, 'revenue' => 100, 'revenue_usd' => 25, 'share' => 0.8]],
                'funnel' => [['stage' => 'sessions', 'count' => 100], ['stage' => 'add_to_carts', 'count' => 20], ['stage' => 'checkouts', 'count' => 8], ['stage' => 'purchases', 'count' => 4]],
            ],
            'ga4_tables' => ['tables' => [
                'sources' => ['rows' => [['name' => 'google', 'sessions' => 50, 'purchases' => 2, 'revenue' => 500, 'revenue_usd' => 125, 'conversion_rate' => 0.04, 'engagement_rate' => 0.5]], 'total' => 1],
                'products' => ['rows' => [['name' => 'abaya', 'item_revenue' => 900, 'items_purchased' => 3]], 'total' => 1],
            ]],
        ];
    }

    public function test_the_marketing_preset_removes_every_sales_number_from_the_json(): void
    {
        $cut = Ga4Visibility::applyToSections($this->sections(), Ga4Visibility::forPreset('marketing'));
        $json = json_encode($cut, JSON_THROW_ON_ERROR);

        $this->assertSame(['sessions', 'bounce_rate'], array_column($cut['ga4_overview']['kpis'], 'key'));
        $this->assertNull($cut['ga4_overview']['series'][0]['revenue']);
        $this->assertSame(100, $cut['ga4_overview']['series'][0]['sessions']);
        $this->assertSame(['sessions', 'add_to_carts', 'checkouts'], array_column($cut['ga4_acquisition']['funnel'], 'stage'));
        $this->assertArrayNotHasKey('products', $cut['ga4_tables']['tables']);
        $this->assertNull($cut['ga4_tables']['tables']['sources']['rows'][0]['revenue']);
        $this->assertSame(50, $cut['ga4_tables']['tables']['sources']['rows'][0]['sessions']);
        foreach (['4000', '"revenue":800', '"revenue_usd":125', '900', 'abaya'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, $needle);
        }
    }

    public function test_the_sales_preset_removes_every_marketing_number(): void
    {
        $cut = Ga4Visibility::applyToSections($this->sections(), Ga4Visibility::forPreset('sales'));
        $this->assertSame(['revenue', 'aov'], array_column($cut['ga4_overview']['kpis'], 'key'));
        $this->assertNull($cut['ga4_overview']['series'][0]['sessions']);
        $this->assertNull($cut['ga4_acquisition']['channels']);
        $this->assertNull($cut['ga4_acquisition']['funnel'] ?? null, 'the funnel is a marketing chart: the sales preset hides it whole');
        $this->assertSame(['products'], array_keys($cut['ga4_tables']['tables']));
    }

    public function test_all_hides_nothing_and_presets_are_recognised(): void
    {
        $this->assertSame($this->sections(), Ga4Visibility::applyToSections($this->sections(), []));
        $this->assertSame('all', Ga4Visibility::presetOf([]));
        $this->assertSame('marketing', Ga4Visibility::presetOf(array_reverse(Ga4Visibility::SALES)));
        $this->assertSame('sales', Ga4Visibility::presetOf(Ga4Visibility::MARKETING));
        $this->assertSame('custom', Ga4Visibility::presetOf(['tile.revenue']));
    }

    public function test_one_column_and_one_platform_row_hide_individually(): void
    {
        $cut = Ga4Visibility::applyToSections($this->sections(), ['col.revenue']);
        $row = $cut['ga4_tables']['tables']['sources']['rows'][0];
        $this->assertNull($row['revenue']);
        $this->assertNull($row['revenue_usd']);
        $this->assertSame(2, $row['purchases'], 'only the chosen column goes');

        $gap = ['ga4_platform_gap' => ['rows' => [['platform' => 'meta', 'reported' => null, 'ga4' => ['purchases' => 3], 'gap' => []], ['platform' => 'snapchat', 'reported' => null, 'ga4' => ['purchases' => 5], 'gap' => []]], 'unpaid' => [], 'other_paid' => null]];
        $cut = Ga4Visibility::applyToSections($gap, ['platform:meta']);
        $this->assertSame(['snapchat'], array_column($cut['ga4_platform_gap']['rows'], 'platform'));
    }

    public function test_a_stored_list_is_normalised_and_the_role_cut_adds_the_sales_set(): void
    {
        $this->assertSame(['tile.revenue', 'platform:meta'], Ga4Visibility::normalise(['tile.revenue', 'platform:meta', 'evil', 5, 'platform:../x', 'tile.revenue']));
        $this->assertSame([], Ga4Visibility::normalise('x'));
        $this->assertSame([], Ga4Visibility::withRole([], true));
        $this->assertSame('marketing', Ga4Visibility::presetOf(Ga4Visibility::withRole([], false)));
    }
}
