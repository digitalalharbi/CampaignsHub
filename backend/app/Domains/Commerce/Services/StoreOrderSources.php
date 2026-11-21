<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Measurement\Ga4TrafficTable;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * STORE-ANALYTICS-DEPTH-001 — the store's confirmed orders, one at a time and per source.
 *
 * Orders come through {@see ProjectOrders} and nothing else, so a shop connected twice counts each sale once and a
 * deselected store is out, exactly as on the funnel. That collapse cannot be expressed in one SQL page, so the page
 * is cut here, on the server; the client only ever receives the page.
 *
 * Nothing here names a customer. GA4's figures for the same source sit in their own `ga4_` fields and are
 * never added to the store's.
 */
final class StoreOrderSources
{
    public const PAGE_SIZES = [10, 25, 50];

    public const GROUPS = ['source_medium', 'campaign', 'content', 'term', 'landing', 'coupon', 'method'];

    public const SOURCE_SORTS = ['dimension_1', 'orders', 'confirmed', 'net_revenue', 'share', 'ga4_sessions', 'ga4_add_to_carts', 'ga4_purchases', 'ga4_revenue', 'confirmed_per_session'];

    public const ORDER_SORTS = ['placed_at', 'net', 'original_total', 'status', 'method', 'source'];

    /** Which stored GA4 breakdown can stand beside a group, and which of its dimensions carries the value. */
    private const GA4_BREAKDOWN = [
        'source_medium' => 'source_medium',
        'campaign' => 'campaign',
        'content' => 'utm_content',
        'term' => 'utm_term',
        'landing' => 'landing_page',
    ];

    public function __construct(
        private readonly ProjectStores $stores,
        private readonly ProjectOrders $orders,
        private readonly CommerceCurrency $currency,
        private readonly ReportingTimezone $timezones,
        private readonly OrderSourceEvidence $evidence,
        private readonly Ga4TrafficTable $traffic,
    ) {}

    /**
     * @param  array{page?: int, per_page?: int, sort?: string, dir?: string, q?: ?string, method?: ?string}  $o
     * @return array<string, mixed>
     */
    public function orders(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $o, bool $all = false): array
    {
        [$sort, $dir, $page, $perPage] = $this->paging($o, self::ORDER_SORTS, 'placed_at');
        [$window, $orders, $hasStore] = $this->load($tenantId, $projectId, $from, $to);
        $base = ['period' => ['from' => $window['from_date'], 'to' => $window['to_date'], 'timezone' => $window['timezone']], 'page' => $page, 'per_page' => $perPage, 'sort' => $sort, 'dir' => $dir];
        if (! $hasStore) {
            return $base + ['state' => 'no_store', 'rows' => [], 'total' => 0, 'pages' => 0, 'currency' => null];
        }

        $providers = $this->campaignProviders($orders);
        $rows = $orders->map(fn (CommerceOrder $order): array => $this->orderRow($order, $providers));
        if (($method = $o['method'] ?? null) !== null && $method !== '') {
            $rows = $rows->where('method', $method);
        }
        if (($term = mb_strtolower(trim((string) ($o['q'] ?? '')))) !== '') {
            $rows = $rows->filter(static fn (array $r): bool => str_contains(mb_strtolower(implode(' ', array_filter([$r['reference'], $r['source'], $r['medium'], $r['campaign'], $r['coupon'], $r['landing_path']]))), $term));
        }

        return $base + $this->cut($rows, $sort, $dir, $page, $perPage, $all) + ['state' => 'ready', 'currency' => $this->currency->reportingCurrencyFor($projectId)];
    }

    /**
     * @param  array{group?: string, page?: int, per_page?: int, sort?: string, dir?: string, q?: ?string, method?: ?string}  $o
     * @return array<string, mixed>
     */
    public function sources(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $o, bool $all = false): array
    {
        $group = $o['group'] ?? 'source_medium';
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException('Unknown group.');
        }
        [$sort, $dir, $page, $perPage] = $this->paging($o, self::SOURCE_SORTS, 'orders');
        [$window, $orders, $hasStore] = $this->load($tenantId, $projectId, $from, $to);
        $base = ['group' => $group, 'period' => ['from' => $window['from_date'], 'to' => $window['to_date'], 'timezone' => $window['timezone']], 'page' => $page, 'per_page' => $perPage, 'sort' => $sort, 'dir' => $dir];
        if (! $hasStore) {
            return $base + ['state' => 'no_store', 'rows' => [], 'total' => 0, 'pages' => 0, 'currency' => null, 'ga4_available' => false, 'totals' => ['orders' => null, 'net_revenue' => null, 'cancelled_excluded' => null, 'money_withheld_orders' => null, 'money_withheld_currencies' => []]];
        }

        $providers = $this->campaignProviders($orders);
        $live = $orders->filter(static fn (CommerceOrder $x): bool => $x->cancelled_at === null);
        $method = $o['method'] ?? null;

        $rows = [];
        $totalLive = max(1, $live->count());
        foreach ($live as $order) {
            $found = $this->evidence->read($order, $providers);
            if ($method !== null && $method !== '' && $found['method'] !== $method) {
                continue;
            }
            [$d1, $d2, $kind] = $this->groupKey($group, $order, $found);
            $key = $kind.'|'.$d1.'|'.$d2;
            $rows[$key] ??= ['dimension_1' => $d1, 'dimension_2' => $d2, 'kind' => $kind, 'orders' => 0, 'confirmed' => 0, 'net_revenue' => 0.0, 'money_withheld_orders' => 0, 'methods' => []];
            $rows[$key]['orders']++;
            $rows[$key]['confirmed'] += in_array(strtolower((string) $order->status), StoreFunnelService::PAID_STATUSES, true) ? 1 : 0;
            $net = $order->netRevenue();
            $net === null ? $rows[$key]['money_withheld_orders']++ : $rows[$key]['net_revenue'] += $net;
            $rows[$key]['methods'][$found['method']] = ($rows[$key]['methods'][$found['method']] ?? 0) + 1;
        }

        $ga4 = $this->ga4Lookup($group, $tenantId, $projectId, $window['from_date'], $window['to_date']);
        $ga4Available = $ga4 !== [];
        foreach ($rows as $key => $row) {
            $rows[$key] = $row + $this->ga4Cells($ga4[$this->ga4Key($group, $row)] ?? null, $row['confirmed']);
            unset($ga4[$this->ga4Key($group, $row)]);
        }
        // A source GA4 measured and the ledger holds nothing for: it was read and has none, so its count is a true 0.
        if ($method === null || $method === '') {
            foreach ($ga4 as $cells) {
                $rows['ga4|'.$cells['d1'].'|'.$cells['d2']] = [
                    'dimension_1' => $cells['d1'], 'dimension_2' => $cells['d2'], 'kind' => 'value', 'orders' => 0, 'confirmed' => 0,
                    'net_revenue' => 0.0, 'money_withheld_orders' => 0, 'methods' => [],
                ] + $this->ga4Cells($cells, 0);
            }
        }

        $list = collect(array_values($rows))->map(static function (array $r) use ($totalLive): array {
            $r['net_revenue'] = round($r['net_revenue'], 2);
            $r['share'] = round($r['orders'] / $totalLive, 4);

            return $r;
        });
        if (($term = mb_strtolower(trim((string) ($o['q'] ?? '')))) !== '') {
            $list = $list->filter(static fn (array $r): bool => str_contains(mb_strtolower((string) $r['dimension_1'].' '.(string) $r['dimension_2']), $term));
        }

        $withheld = $live->filter(static fn (CommerceOrder $x): bool => $x->moneyWithheld());

        return $base + $this->cut($list, $sort, $dir, $page, $perPage, $all) + [
            'state' => 'ready',
            'currency' => $this->currency->reportingCurrencyFor($projectId),
            'ga4_available' => $ga4Available,
            'totals' => [
                'orders' => $live->count(),
                'net_revenue' => round((float) $live->sum(static fn (CommerceOrder $x) => $x->netRevenue() ?? 0.0), 2),
                'cancelled_excluded' => $orders->count() - $live->count(),
                'money_withheld_orders' => $withheld->count(),
                'money_withheld_currencies' => $withheld->pluck('original_currency')->filter()->unique()->values()->all(),
            ],
        ];
    }

    /**
     * Live orders counted per evidence method: what a client section reads. It holds no campaign name, no
     * UTM text and no referring host.
     *
     * @param  Collection<int, CommerceOrder>  $orders
     * @return array<string, array{orders: int, net_revenue: float}>
     */
    public function evidenceTotals(Collection $orders): array
    {
        $providers = $this->campaignProviders($orders);
        $methods = [];
        foreach ($orders as $order) {
            if ($order->cancelled_at !== null) {
                continue;
            }
            $found = $this->evidence->read($order, $providers);
            $methods[$found['method']] ??= ['orders' => 0, 'net_revenue' => 0.0];
            $methods[$found['method']]['orders']++;
            $methods[$found['method']]['net_revenue'] += $order->netRevenue() ?? 0.0;
        }
        foreach ($methods as $k => $m) {
            $methods[$k]['net_revenue'] = round($m['net_revenue'], 2);
        }

        return $methods;
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, CommerceOrder>, 2: bool} */
    private function load(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $orders = $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders'];

        return [$window, $orders, $this->stores->forProject($tenantId, $projectId)->isNotEmpty()];
    }

    /**
     * @param  array<string, mixed>  $o
     * @param  list<string>  $sorts
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private function paging(array $o, array $sorts, string $default): array
    {
        $sort = $o['sort'] ?? $default;
        if (! in_array($sort, $sorts, true)) {
            throw new InvalidArgumentException('Unknown sort column.');
        }
        $perPage = in_array((int) ($o['per_page'] ?? 10), self::PAGE_SIZES, true) ? (int) ($o['per_page'] ?? 10) : 10;

        return [$sort, strtolower((string) ($o['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc', max(1, (int) ($o['page'] ?? 1)), $perPage];
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array{rows: list<array<string, mixed>>, total: int, pages: int}
     */
    private function cut(Collection $rows, string $sort, string $dir, int $page, int $perPage, bool $all): array
    {
        $rows = $rows->values();
        $present = $rows->filter(static fn (array $r): bool => $r[$sort] !== null);
        $absent = $rows->filter(static fn (array $r): bool => $r[$sort] === null);
        // Absent sorts last in both directions; ties keep their first-seen order so pages never overlap.
        $sorted = $present->sortBy($sort, SORT_REGULAR, $dir === 'desc')->concat($absent)->values();
        $total = $sorted->count();

        return [
            'rows' => $all ? $sorted->all() : $sorted->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'total' => $total,
            'pages' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    /**
     * @param  array<string, string>  $providers
     * @return array<string, mixed>
     */
    private function orderRow(CommerceOrder $order, array $providers): array
    {
        $found = $this->evidence->read($order, $providers);

        return [
            'id' => (string) $order->getKey(),
            'reference' => $order->reference,
            'placed_at' => $order->placed_at?->toIso8601String(),
            'status' => (string) $order->status,
            'confirmed' => $order->cancelled_at === null && in_array(strtolower((string) $order->status), StoreFunnelService::PAID_STATUSES, true),
            'cancelled' => $order->cancelled_at !== null,
            'refunded' => (float) $order->refunded_total > 0,
            'original_total' => $order->original_total !== null ? (float) $order->original_total : ($order->total !== null ? (float) $order->total : null),
            'original_currency' => $order->original_currency ?? $order->currency,
            'net' => $order->netRevenue(),
            'money_withheld' => $order->moneyWithheld(),
            'method' => $found['method'],
            'method_label' => OrderSourceEvidence::LABELS[$found['method']],
            'source' => $found['source'],
            'medium' => $found['medium'],
            'campaign' => $order->utm_campaign,
            'content' => $order->utm_content,
            'term' => $order->utm_term,
            'coupon' => $order->coupon_code,
            'click_id_platform' => $order->click_id_provider,
            'landing_path' => $this->evidence->landingPath($order->landing_url),
            'is_demo' => (bool) $order->is_demo,
        ];
    }

    /**
     * @param  array{method: string, source: ?string, medium: ?string}  $found
     * @return array{0: string, 1: ?string, 2: string}
     */
    private function groupKey(string $group, CommerceOrder $order, array $found): array
    {
        if ($group === 'method') {
            return [$found['method'], null, 'method'];
        }
        if ($group === 'source_medium') {
            return match ($found['method']) {
                'unattributed' => ['unattributed', null, 'unattributed'],
                'conflict' => ['conflict', null, 'conflict'],
                default => [(string) $found['source'], $found['medium'], 'value'],
            };
        }
        $value = match ($group) {
            'campaign' => $order->utm_campaign,
            'content' => $order->utm_content,
            'term' => $order->utm_term,
            'coupon' => $order->coupon_code,
            default => $this->evidence->landingPath($order->landing_url),
        };
        $value = trim((string) $value);

        return $value === '' ? ['not_tagged', null, 'not_tagged'] : [$value, null, 'value'];
    }

    /** @param array<string, mixed> $row */
    private function ga4Key(string $group, array $row): string
    {
        return $group === 'source_medium'
            ? mb_strtolower((string) $row['dimension_1']).'|'.mb_strtolower((string) ($row['dimension_2'] ?? ''))
            : mb_strtolower((string) $row['dimension_1']);
    }

    /** @return array<string, array<string, mixed>> */
    private function ga4Lookup(string $group, string $tenantId, string $projectId, string $fromDate, string $toDate): array
    {
        $breakdown = self::GA4_BREAKDOWN[$group] ?? null;
        if ($breakdown === null) {
            return [];
        }
        $read = $this->traffic->page($tenantId, $projectId, Carbon::parse($fromDate), Carbon::parse($toDate), ['breakdown' => $breakdown], all: true);
        if (($read['state'] ?? null) !== 'ready') {
            return [];
        }

        $out = [];
        foreach ($read['rows'] as $row) {
            $d2 = $group === 'source_medium' ? ($row['dimension_2'] ?? null) : null;
            $key = $this->ga4Key($group, ['dimension_1' => $row['dimension_1'], 'dimension_2' => $d2]);
            $out[$key] ??= ['d1' => $row['dimension_1'], 'd2' => $d2, 'sessions' => null, 'add_to_carts' => null, 'purchases' => null, 'revenue' => null, 'currency' => $read['currency'] ?? null];
            foreach (['sessions', 'add_to_carts', 'purchases', 'revenue'] as $metric) {
                if ($row[$metric] !== null) {
                    $out[$key][$metric] = ($out[$key][$metric] ?? 0.0) + $row[$metric];
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $cells
     * @return array<string, mixed>
     */
    private function ga4Cells(?array $cells, int $confirmed): array
    {
        $sessions = $cells['sessions'] ?? null;

        return [
            'ga4_sessions' => $sessions,
            'ga4_add_to_carts' => $cells['add_to_carts'] ?? null,
            'ga4_purchases' => $cells['purchases'] ?? null,
            'ga4_revenue' => $cells['revenue'] ?? null,
            'ga4_currency' => $cells['currency'] ?? null,
            // The store's confirmed orders over GA4's sessions: two systems, named as such on the page, and only where GA4 counted visits.
            'confirmed_per_session' => $sessions !== null && $sessions > 0 ? round($confirmed / $sessions, 4) : null,
        ];
    }

    /**
     * @param  Collection<int, CommerceOrder>  $orders
     * @return array<string, string>
     */
    private function campaignProviders(Collection $orders): array
    {
        $ids = $orders->pluck('external_campaign_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : ExternalCampaign::withoutGlobalScopes()->whereIn('id', $ids)->pluck('provider', 'id')->all();
    }
}
