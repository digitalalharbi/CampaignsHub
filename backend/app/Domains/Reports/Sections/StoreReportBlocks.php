<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

/**
 * STORE-REPORT-SECTIONS-001 — the store's own ledger (CommerceInsightsService) cut into the four report sections a client can be shown.
 *
 *   store_sales     sales, growth, trend, provenance        (Store & Sales Intelligence)
 *   store_products  products, SKUs, categories              (Products / SKUs / Categories)
 *   store_orders    orders, refunds, discounts, coupons, carts
 *   store_audience  cities, new/returning customers         (Cities / Customer Segments)
 *
 * Each block is independent so each section can be shown, hidden and ordered on its own, and each carries the currency, window and
 * source it was measured with — a hidden neighbour never takes the provenance with it. A section that has nothing to say is ABSENT
 * (null), never present-and-empty.
 *
 * Withholding: a link that hides revenue empties EVERY money field here (sales, AOV, discounts, refunds, shipping, tax, per-product
 * and per-city sales), because a refund or a discount next to an order count is enough to divide back to the revenue that was hidden.
 * Counts, shares and names stay. The block names what it withheld so the reader is not told the store sells nothing.
 *
 * Store-confirmed orders are the reference; nothing here is an advertising or GA4 figure, and none is added to one.
 */
final class StoreReportBlocks
{
    public const KEYS = ['store_sales', 'store_products', 'store_orders', 'store_audience'];

    /**
     * @param  array<string,mixed>  $insights  CommerceInsightsService::build()
     * @return array<string, array<string,mixed>|null>
     */
    public static function from(array $insights, bool $hideRevenue): array
    {
        $none = array_fill_keys(self::KEYS, null);
        $t = (array) ($insights['totals'] ?? []);
        if (! (bool) ($insights['provenance']['has_store'] ?? false) || (int) ($t['orders'] ?? 0) === 0) {
            return $none;
        }

        $meta = [
            'currency' => $insights['provenance']['currency'] ?? null,
            'window' => $insights['window'] ?? null,
            'stores' => array_map(static fn (array $s): array => ['name' => $s['name'], 'provider' => $s['provider'], 'last_synced_at' => $s['last_synced_at']], (array) ($insights['provenance']['stores'] ?? [])),
            'orders_with_money_withheld' => (int) ($insights['provenance']['orders_with_money_withheld'] ?? 0),
            'duplicates_collapsed' => (int) ($insights['provenance']['duplicates_collapsed'] ?? 0),
        ];

        $blocks = [
            'store_sales' => $meta + ['totals' => $t, 'previous' => $insights['previous'] ?? null, 'growth' => $insights['growth'] ?? null, 'trend' => $insights['trend'] ?? []],
            'store_products' => $meta + ['products' => $insights['products'] ?? [], 'categories' => $insights['categories'] ?? []],
            'store_orders' => $meta + [
                'totals' => array_intersect_key($t, array_flip(['orders', 'cancelled_orders', 'discounts', 'refunds', 'refund_rate', 'shipping', 'tax', 'gross_sales'])),
                'coupons' => $insights['coupons'] ?? [], 'abandoned_carts' => $insights['abandoned_carts'] ?? null,
            ],
            'store_audience' => $meta + ['geography' => $insights['geography'] ?? [], 'customers' => $insights['customers'] ?? []],
        ];

        return $hideRevenue ? array_map(static fn (array $b): array => self::withheld($b), $blocks) : $blocks;
    }

    /** Every money field emptied, by NAME so a new money field cannot slip through unnoticed in a nested list. */
    private const MONEY = ['gross_sales', 'subtotal', 'discounts', 'shipping', 'tax', 'refunds', 'net_sales', 'aov', 'refund_rate', 'sales', 'discount', 'value'];

    /**
     * @param  array<string,mixed>  $block
     * @return array<string,mixed>
     */
    private static function withheld(array $block): array
    {
        $walk = static function (mixed $node) use (&$walk): mixed {
            if (! is_array($node)) {
                return $node;
            }
            $out = [];
            foreach ($node as $k => $v) {
                $out[$k] = is_string($k) && in_array($k, self::MONEY, true) ? null : $walk($v);
            }

            return $out;
        };
        // `net_sales` also names a per-day and per-city/-segment figure; the walk covers those lists too.
        $walked = $walk($block);
        $walked['currency'] = null;
        $walked['withheld'] = ['money'];

        return $walked;
    }
}
