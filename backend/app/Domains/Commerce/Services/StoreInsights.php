<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use Illuminate\Support\Collection;

/**
 * STORE-INSIGHTS-001 (Owner 2026-11-25) — «جانب إبداعي وتحليلي عميق جدًا لتقارير المتجر … بطاقات كبيرة وصغيرة … دائرية».
 *
 * The deeper reading of a store's own orders that a Salla or Zid merchant expects from their own dashboard, computed from
 * what the sync already stores and nothing else: when people buy (weekday × hour, in the merchant's own zone), how they pay,
 * where they are (the customer's city), what state their orders are in, how big a basket is, which coupons carried sales and
 * what the money is made of. Every share is a share of the orders that stated the field; an order that did not state it is
 * counted apart as «غير محدد», never spread over the others. No customer name, email or phone is read.
 */
final class StoreInsights
{
    /** Beyond this many slices a donut stops being readable; the rest is one «أخرى» slice with its count. */
    private const SLICES = 6;

    private const COUPONS = 8;

    /**
     * @param  Collection<int, CommerceOrder>  $orders  the period's deduplicated orders, cancelled included
     * @return array<string, mixed>
     */
    public function build(Collection $orders, string $timezone): array
    {
        $live = $orders->filter(static fn (CommerceOrder $o): bool => $o->cancelled_at === null)->values();
        // One read for every customer of the period, the city column only.
        $ids = $live->pluck('commerce_customer_id')->filter()->unique()->values()->all();
        $cities = $ids === [] ? collect() : CommerceCustomer::withoutGlobalScopes()->whereIn('id', $ids)->pluck('city', 'id');

        return [
            'status_mix' => $this->statusMix($orders),
            'payment_mix' => $this->mix($live, static fn (CommerceOrder $o): ?string => self::clean($o->payment_status)),
            'city_mix' => $this->mix($live, static fn (CommerceOrder $o): ?string => $o->commerce_customer_id === null ? null : self::clean($cities[$o->commerce_customer_id] ?? null)),
            'heatmap' => $this->heatmap($live, $timezone),
            'basket' => $this->basket($live),
            'coupons' => $this->coupons($live),
            'composition' => $this->composition($live),
        ];
    }

    /**
     * Every order's state, cancelled included: the one view where a cancelled order is the point.
     *
     * @param  Collection<int, CommerceOrder>  $orders
     * @return list<array{key: string, orders: int, share: float}>
     */
    private function statusMix(Collection $orders): array
    {
        $total = $orders->count();
        if ($total === 0) {
            return [];
        }

        return $orders
            ->groupBy(static fn (CommerceOrder $o): string => $o->cancelled_at !== null ? 'cancelled' : (self::clean($o->status) ?? 'unknown'))
            ->map(static fn (Collection $g, string $key): array => ['key' => $key, 'orders' => $g->count(), 'share' => round($g->count() / $total, 4)])
            ->sortByDesc('orders')->values()->all();
    }

    /**
     * Orders and net sales per value of one field, largest first, folded to SLICES with «أخرى».
     *
     * @param  Collection<int, CommerceOrder>  $live
     * @param  callable(CommerceOrder): ?string  $of
     * @return array{rows: list<array{key: string, orders: int, net: ?float, share: float}>, other: ?array{orders: int, net: ?float, keys: int, share: float}, unstated: int, stated: int}
     */
    private function mix(Collection $live, callable $of): array
    {
        $stated = $live->filter(static fn (CommerceOrder $o): bool => $of($o) !== null);
        $groups = $stated->groupBy(static fn (CommerceOrder $o): string => (string) $of($o))
            ->map(static fn (Collection $g, string $key): array => ['key' => $key, 'orders' => $g->count(), 'net' => self::net($g)])
            ->sortByDesc('orders')->values();

        $count = $stated->count();
        $withShare = static fn (array $r): array => $r + ['share' => $count > 0 ? round($r['orders'] / $count, 4) : 0.0];
        $head = $groups->take(self::SLICES)->map($withShare)->values()->all();
        $tail = $groups->slice(self::SLICES);

        return [
            'rows' => $head,
            'other' => $tail->isEmpty() ? null : [
                'orders' => (int) $tail->sum('orders'),
                'net' => $tail->contains(static fn (array $r): bool => $r['net'] === null) ? null : round((float) $tail->sum('net'), 2),
                'keys' => $tail->count(),
                'share' => $count > 0 ? round($tail->sum('orders') / $count, 4) : 0.0,
            ],
            'unstated' => $live->count() - $count,
            'stated' => $count,
        ];
    }

    /**
     * Orders by weekday (0 = Sunday, as a Saudi week reads) and hour, in the merchant's own zone.
     *
     * @param  Collection<int, CommerceOrder>  $live
     * @return array{cells: list<list<int>>, max: int, peak: ?array{day: int, hour: int, orders: int}, by_day: list<int>, by_hour: list<int>, timezone: string}
     */
    private function heatmap(Collection $live, string $timezone): array
    {
        $cells = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($live as $order) {
            if ($order->placed_at === null) {
                continue;
            }
            $at = $order->placed_at->copy()->setTimezone($order->placed_at_timezone ?: $timezone);
            $cells[(int) $at->dayOfWeek][(int) $at->hour]++;
        }

        $max = 0;
        $peak = null;
        foreach ($cells as $day => $hours) {
            foreach ($hours as $hour => $n) {
                if ($n > $max) {
                    $max = $n;
                    $peak = ['day' => $day, 'hour' => $hour, 'orders' => $n];
                }
            }
        }

        return [
            'cells' => $cells,
            'max' => $max,
            'peak' => $peak,
            'by_day' => array_map(static fn (array $h): int => array_sum($h), $cells),
            'by_hour' => array_map(static fn (int $h): int => array_sum(array_column($cells, $h)), range(0, 23)),
            'timezone' => $timezone,
        ];
    }

    /**
     * How big a basket is: the order totals in five bands whose edges follow the period's own spread, so a coffee shop
     * and a jeweller both get a readable picture. Orders whose total was withheld are not banded.
     *
     * @param  Collection<int, CommerceOrder>  $live
     * @return array{bands: list<array{from: float, to: ?float, orders: int, net: float}>, median: ?float}
     */
    private function basket(Collection $live): array
    {
        $values = $live->map(static fn (CommerceOrder $o): ?float => $o->netRevenue())->filter(static fn (?float $v): bool => $v !== null && $v > 0)->sort()->values();
        if ($values->count() < 5) {
            return ['bands' => [], 'median' => $values->isEmpty() ? null : round((float) $values->median(), 2)];
        }

        $median = (float) $values->median();
        $step = self::nice($median / 2);
        $edges = [0.0, $step, $step * 2, $step * 3, $step * 5];
        $bands = [];
        foreach ($edges as $i => $from) {
            $to = $edges[$i + 1] ?? null;
            $in = $values->filter(static fn (float $v): bool => $v >= $from && ($to === null || $v < $to));
            $bands[] = ['from' => $from, 'to' => $to, 'orders' => $in->count(), 'net' => round((float) $in->sum(), 2)];
        }

        return ['bands' => $bands, 'median' => round($median, 2)];
    }

    /**
     * Coupons that carried orders: how many orders, the discount they gave and the net they brought.
     *
     * @param  Collection<int, CommerceOrder>  $live
     * @return array{rows: list<array{code: string, orders: int, discount: ?float, net: ?float}>, orders_with_coupon: int, share: ?float}
     */
    private function coupons(Collection $live): array
    {
        $with = $live->filter(static fn (CommerceOrder $o): bool => self::clean($o->coupon_code) !== null);
        $rows = $with->groupBy(static fn (CommerceOrder $o): string => mb_strtoupper((string) self::clean($o->coupon_code)))
            ->map(static fn (Collection $g, string $code): array => [
                'code' => $code,
                'orders' => $g->count(),
                'discount' => $g->contains(static fn (CommerceOrder $o): bool => $o->discount_total === null) ? null : round((float) $g->sum('discount_total'), 2),
                'net' => self::net($g),
            ])->sortByDesc('orders')->values()->take(self::COUPONS)->all();

        return ['rows' => $rows, 'orders_with_coupon' => $with->count(), 'share' => $live->isEmpty() ? null : round($with->count() / $live->count(), 4)];
    }

    /**
     * What the money is made of: products, shipping and tax that customers paid, the discounts they were given and what
     * was refunded. Each part is null when no order stated it.
     *
     * @param  Collection<int, CommerceOrder>  $live
     * @return array<string, ?float>
     */
    private function composition(Collection $live): array
    {
        $sum = static function (string $column) use ($live): ?float {
            $v = $live->pluck($column)->filter(static fn ($x): bool => $x !== null);

            return $v->isEmpty() ? null : round((float) $v->sum(), 2);
        };

        return [
            'products' => $sum('subtotal'),
            'shipping' => $sum('shipping_total'),
            'tax' => $sum('tax_total'),
            'discounts' => $sum('discount_total'),
            'refunded' => $sum('refunded_total'),
        ];
    }

    /** @param Collection<int, CommerceOrder> $orders */
    private static function net(Collection $orders): ?float
    {
        $values = $orders->map(static fn (CommerceOrder $o): ?float => $o->netRevenue());

        return $values->contains(null) ? null : round((float) $values->sum(), 2);
    }

    private static function clean(mixed $v): ?string
    {
        $s = is_string($v) ? trim($v) : (is_scalar($v) ? (string) $v : '');

        return $s === '' ? null : $s;
    }

    /** A round step (1, 2, 2.5 or 5 × a power of ten) at or above the value, so band edges read like a person wrote them. */
    private static function nice(float $value): float
    {
        if ($value <= 0) {
            return 1.0;
        }
        $power = 10 ** floor(log10($value));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $power >= $value) {
                return $m * $power;
            }
        }

        return 10 * $power;
    }
}
