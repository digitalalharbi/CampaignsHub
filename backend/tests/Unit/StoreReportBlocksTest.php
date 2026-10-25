<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Reports\Sections\StoreReportBlocks;
use PHPUnit\Framework\TestCase;

/**
 * STORE-REPORT-SECTIONS-001 — the four store sections a client can be shown, and what a link that hides revenue may not leak.
 */
final class StoreReportBlocksTest extends TestCase
{
    /** @return array<string,mixed> */
    private function insights(bool $store = true, int $orders = 4): array
    {
        return [
            'window' => ['from' => '2026-10-01', 'to' => '2026-10-07', 'timezone' => 'Asia/Riyadh', 'days' => 7, 'previous' => ['from' => '2026-09-24', 'to' => '2026-09-30']],
            'provenance' => ['currency' => 'SAR', 'has_store' => $store, 'duplicates_collapsed' => 1, 'orders_with_money_withheld' => 1, 'orders_with_assumed_timezone' => 0,
                'stores' => [['id' => 's1', 'name' => 'Demo store', 'provider' => 'salla', 'last_synced_at' => '2026-10-07T10:00:00+00:00']]],
            'totals' => ['orders' => $orders, 'cancelled_orders' => 1, 'orders_priced' => 3, 'gross_sales' => 600.0, 'subtotal' => 575.0, 'discounts' => 50.0, 'shipping' => 15.0, 'tax' => 30.0, 'refunds' => 40.0, 'net_sales' => 560.0, 'aov' => 186.67, 'refund_rate' => 0.0667],
            'previous' => ['orders' => 1, 'gross_sales' => 400.0, 'discounts' => 0.0, 'refunds' => 0.0, 'net_sales' => 400.0, 'aov' => 400.0],
            'growth' => ['orders' => 300.0, 'gross_sales' => 50.0, 'discounts' => null, 'refunds' => null, 'net_sales' => 40.0, 'aov' => -53.3],
            'trend' => [['date' => '2026-10-02', 'orders' => 1, 'net_sales' => 200.0]],
            'products' => ['by_product' => ['count' => 1, 'best' => [['key' => 'SH-1', 'name' => 'Shoes', 'sku' => 'SH-1', 'quantity' => 5, 'sales' => 460.0, 'orders' => 2]], 'worst' => []], 'by_sku' => ['count' => 0, 'best' => [], 'worst' => []], 'units' => 5, 'lines_without_sku' => 0],
            'categories' => ['rows' => [['category' => 'Footwear', 'quantity' => 5, 'sales' => 460.0]], 'uncategorised_share' => 0.0],
            'geography' => ['basis' => 'customer_city', 'shipping_destination' => 'not_available', 'rows' => [['city' => 'Riyadh', 'orders' => 2, 'net_sales' => 300.0, 'share' => 0.5]], 'unknown_orders' => 0],
            'customers' => ['new' => ['customers' => 1, 'orders' => 1, 'net_sales' => 200.0], 'returning' => ['customers' => 2, 'orders' => 2, 'net_sales' => 360.0], 'orders_without_customer' => 0, 'retention' => null, 'retention_reason' => 'needs_cohort_history'],
            'coupons' => ['orders_with_coupon' => 2, 'share_of_orders' => 0.5, 'rows' => [['code' => 'SAVE10', 'orders' => 2, 'discount' => 50.0, 'net_sales' => 500.0]]],
            'abandoned_carts' => ['supported' => true, 'count' => 1, 'value' => 80.0, 'complete' => true],
            'profitability' => ['available' => false, 'reason' => 'no_cost_data'],
        ];
    }

    /** @param array<string,mixed> $node @return list<string> money-named keys that still hold a number */
    private function leaks(array $node, string $path = ''): array
    {
        $money = ['gross_sales', 'subtotal', 'discounts', 'shipping', 'tax', 'refunds', 'net_sales', 'aov', 'refund_rate', 'sales', 'discount', 'value'];
        $found = [];
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $found = array_merge($found, $this->leaks($v, $path.'.'.$k));
            } elseif (in_array($k, $money, true) && $v !== null) {
                $found[] = $path.'.'.$k.'='.$v;
            }
        }

        return $found;
    }

    public function test_there_are_four_independent_blocks_each_carrying_its_own_provenance(): void
    {
        $b = StoreReportBlocks::from($this->insights(), false);

        $this->assertSame(['store_sales', 'store_products', 'store_orders', 'store_audience'], array_keys($b));
        foreach ($b as $name => $block) {
            $this->assertSame('SAR', $block['currency'], $name);
            $this->assertSame('Asia/Riyadh', $block['window']['timezone'], $name);
            $this->assertSame('Demo store', $block['stores'][0]['name'], $name);
            $this->assertSame(1, $block['orders_with_money_withheld'], $name);
        }
        $this->assertSame(560.0, $b['store_sales']['totals']['net_sales']);
        $this->assertSame(460.0, $b['store_products']['products']['by_product']['best'][0]['sales']);
        $this->assertSame(40.0, $b['store_orders']['totals']['refunds']);
        $this->assertSame('Riyadh', $b['store_audience']['geography']['rows'][0]['city']);
        $this->assertSame('needs_cohort_history', $b['store_audience']['customers']['retention_reason']);
    }

    public function test_a_block_with_nothing_to_say_is_absent_not_empty(): void
    {
        $this->assertSame([null, null, null, null], array_values(StoreReportBlocks::from($this->insights(store: false), false)));
        $this->assertSame([null, null, null, null], array_values(StoreReportBlocks::from($this->insights(orders: 0), false)));
    }

    public function test_the_orders_block_carries_no_net_sales_and_the_sales_block_no_coupons(): void
    {
        $b = StoreReportBlocks::from($this->insights(), false);
        $this->assertArrayNotHasKey('net_sales', $b['store_orders']['totals']);
        $this->assertArrayNotHasKey('coupons', $b['store_sales']);
    }

    public function test_hiding_revenue_empties_every_money_field_at_every_depth_in_every_block(): void
    {
        foreach (StoreReportBlocks::from($this->insights(), true) as $name => $block) {
            $this->assertSame([], $this->leaks($block), "$name leaks money");
            $this->assertNull($block['currency'], "$name still names a currency for withheld money");
            $this->assertSame(['money'], $block['withheld']);
        }
    }

    public function test_hiding_revenue_keeps_counts_names_shares_and_the_trend_dates(): void
    {
        $b = StoreReportBlocks::from($this->insights(), true);

        $this->assertSame(4, $b['store_sales']['totals']['orders']);
        $this->assertSame('2026-10-02', $b['store_sales']['trend'][0]['date']);
        $this->assertSame(1, $b['store_sales']['trend'][0]['orders']);
        $this->assertNull($b['store_sales']['trend'][0]['net_sales']);
        $this->assertSame('Shoes', $b['store_products']['products']['by_product']['best'][0]['name']);
        $this->assertSame(5, $b['store_products']['products']['by_product']['best'][0]['quantity']);
        $this->assertSame(0.5, $b['store_audience']['geography']['rows'][0]['share']);
        $this->assertSame(2, $b['store_orders']['coupons']['rows'][0]['orders']);
        $this->assertNull($b['store_orders']['coupons']['rows'][0]['discount']);
        $this->assertNull($b['store_orders']['abandoned_carts']['value']);
        $this->assertSame(1, $b['store_orders']['abandoned_carts']['count']);
    }

    public function test_growth_percentages_of_revenue_are_withheld_with_it(): void
    {
        $g = StoreReportBlocks::from($this->insights(), true)['store_sales']['growth'];
        $this->assertNull($g['net_sales']);
        $this->assertNull($g['gross_sales']);
        $this->assertSame(300.0, $g['orders'], 'an order count growth is not revenue');
    }
}
