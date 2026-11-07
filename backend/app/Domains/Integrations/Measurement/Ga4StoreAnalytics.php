<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Metrics\Services\CurrencyConverter;
use App\Domains\Reports\Support\ReportReadMemo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GA4-STORE-ANALYTICS-001 — the store as its own GA4 property measured it: visits, bounce, sales,
 * basket size, where the visits came from, and how that differs from what the ad platforms reported.
 *
 * One reading, served to Analytics, the live link and the saved report alike, so the three cannot
 * disagree. Rules the numbers obey:
 *   - scope is tenant + project + the project's own selected property + window, all in the WHERE;
 *     a project never reads another project's property;
 *   - counts are summed; rates are weighted by sessions; AOV is revenue ÷ purchases and is WITHHELD
 *     (null) when there were no purchases, never drawn as 0;
 *   - revenue is kept in the property's currency and also stated in USD, converted day by day at
 *     the stored rate for that day. A day with revenue and no rate withholds the USD figure — it is
 *     never guessed and never converted at another day's rate from a different pair;
 *   - GA4 figures are never added to an ad platform's: the gap is a comparison (`summed: false`).
 */
final class Ga4StoreAnalytics
{
    /** The KPI tiles, in reading order: traffic, quality, then the shop. */
    public const KPIS = [
        'sessions', 'users_daily_sum', 'new_users', 'bounce_rate', 'engagement_rate', 'avg_session_seconds',
        'transactions', 'revenue', 'aov', 'conversion_rate', 'add_to_carts', 'checkouts',
    ];

    /** table → stored breakdown */
    public const TABLES = [
        'sources' => 'source_medium',
        'campaigns' => 'campaign',
        'landing_pages' => 'landing_page',
        'products' => 'product',
        'countries' => 'country',
    ];

    /** A table carries at most this many rows; `total` states how many there were. */
    public const TABLE_ROWS = 200;

    /** @var array<string, ?float> date → rate to USD, misses included */
    private array $rates = [];

    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly Ga4PlatformGap $gap,
    ) {}

    /**
     * @param  array<string, array{conversions: ?float, revenue: ?float, currency: ?string}>|null  $reported  per ad platform, already scoped by the caller; null leaves the gap's platform side absent
     * @return array<string, mixed>
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, ?array $reported = null): array
    {
        $this->rates = [];
        $period = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        $property = $this->property($tenantId, $projectId);

        if ($property === null) {
            $connected = ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->exists();

            return ['state' => $connected ? 'not_selected' : 'not_connected', 'period' => $period];
        }

        $accountId = (string) $property->getKey();
        $currency = $property->currency !== null && $property->currency !== '' ? strtoupper((string) $property->currency) : null;
        $kpis = (new Ga4Kpis)->build($tenantId, $projectId, $accountId, $from, $to, $property->last_synced_at, $currency);

        $rows = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->whereIn('breakdown', [...array_values(self::TABLES), 'device', 'default_channel_group'])
            ->whereBetween('metric_date', [$period['from'], $period['to']])
            ->get(['metric_date', 'breakdown', 'dimension_1', 'dimension_2', 'metrics'])
            ->map(static fn (object $r): array => [
                'date' => substr((string) $r->metric_date, 0, 10),
                'breakdown' => (string) $r->breakdown,
                'd1' => (string) $r->dimension_1,
                'd2' => (string) $r->dimension_2,
                'm' => (array) json_decode((string) $r->metrics, true),
            ])->groupBy('breakdown')->map(static fn ($g): array => $g->values()->all())->all();

        $base = [
            'property' => ['id' => (string) $property->external_id, 'name' => $property->name, 'timezone' => $property->timezone],
            'period' => $period,
            'previous_period' => $kpis['previous_period'],
            'last_synced_at' => $property->last_synced_at?->toIso8601String(),
        ];

        $measured = collect($kpis['kpis'])->contains(static fn (array $k): bool => $k['current'] !== null);
        if (! $measured && $rows === []) {
            return ['state' => 'empty'] + $base;
        }

        $daily = $this->dailyRevenue($tenantId, $projectId, $accountId, Carbon::parse($kpis['previous_period']['from']), $to);
        $revenueUsd = [
            'current' => $this->windowUsd($daily, $currency, $period['from'], $period['to']),
            'previous' => $this->windowUsd($daily, $currency, $kpis['previous_period']['from'], $kpis['previous_period']['to']),
        ];

        $tiles = [];
        $byKey = collect($kpis['kpis'])->keyBy('key');
        foreach (self::KPIS as $key) {
            $k = $byKey[$key] ?? null;
            if ($k === null) {
                continue;
            }
            $tile = [
                'key' => $key, 'kind' => $k['kind'], 'current' => $k['current'], 'previous' => $k['previous'],
                'change_pct' => $k['change_pct'], 'currency' => $k['currency'], 'trend' => $k['trend'],
            ];
            if ($k['kind'] === 'money') {
                $tile['current_usd'] = $this->kpiUsd($key, $revenueUsd['current'], $byKey, 'current');
                $tile['previous_usd'] = $this->kpiUsd($key, $revenueUsd['previous'], $byKey, 'previous');
            }
            $tiles[] = $tile;
        }

        // WEBSITE-TOTALS-001 — the visits figure is the tile's: every breakdown of it is closed against THIS number.
        $headline = isset($byKey['sessions']['current']) ? (float) $byKey['sessions']['current'] : null;
        $dayCount = $this->sessionDays($tenantId, $projectId, $accountId, $period);
        $totalsSource = $rows['device'] ?? $rows['source_medium'] ?? $rows['landing_page'] ?? [];
        $visits = $this->aggregate($rows['source_medium'] ?? [], $currency, static fn (array $r): string => $r['d1']."\u{1F}".$r['d2']);

        return ['state' => 'ready'] + $base + [
            'currency' => $currency,
            'usd' => $this->usdState($currency, $daily, $period),
            'not_summed' => true,
            'kpis' => $tiles,
            'series' => $this->series($tenantId, $projectId, $accountId, $currency, $period),
            'channels' => $this->slices($rows['default_channel_group'] ?? null, $currency, $headline),
            'channels_meta' => $this->sliceMeta($rows['default_channel_group'] ?? null, $currency, $headline, $dayCount),
            'devices' => $this->slices($rows['device'] ?? null, $currency, $headline),
            'devices_meta' => $this->sliceMeta($rows['device'] ?? null, $currency, $headline, $dayCount),
            'funnel' => $this->funnel($totalsSource, $byKey),
            'gap' => $this->gap->build(
                $this->reportedUsd($reported ?? [], $to),
                array_map(static fn (array $v): array => [
                    'source' => $v['d1'], 'medium' => $v['d2'], 'sessions' => (float) ($v['sessions'] ?? 0), 'purchases' => (float) ($v['purchases'] ?? 0),
                    'revenue' => (float) ($v['revenue'] ?? 0), 'revenue_usd' => $v['revenue_usd'],
                ], $visits),
                $currency,
            ) + ['platforms_available' => $reported !== null],
            'tables' => [
                'sources' => $this->sessionTable($visits, static fn (array $r): array => ['name' => $r['d1'], 'sub' => $r['d2'] !== '' ? $r['d2'] : null]),
                'campaigns' => $this->sessionTable(
                    isset($rows['campaign']) ? $this->aggregate($rows['campaign'], $currency, static fn (array $r): string => $r['d1']."\u{1F}".$r['d2']) : null,
                    static fn (array $r): array => ['name' => $r['d1'], 'sub' => $r['d2'] !== '' ? $r['d2'] : null],
                ),
                'landing_pages' => $this->sessionTable(
                    isset($rows['landing_page']) ? $this->aggregate($rows['landing_page'], $currency, static fn (array $r): string => $r['d1']) : null,
                    static fn (array $r): array => ['name' => $r['d1'], 'sub' => null],
                ),
                'countries' => $this->sessionTable(
                    isset($rows['country']) ? $this->aggregate($rows['country'], $currency, static fn (array $r): string => $r['d1']) : null,
                    static fn (array $r): array => ['name' => $r['d1'], 'sub' => null],
                ),
                'products' => $this->productTable($rows['product'] ?? null, $currency),
            ],
        ];
    }

    private function property(string $tenantId, string $projectId): ?ExternalAccount
    {
        // PERF-RECOVERY-001 — one read per build: the site, the store reading and the traffic split all ask it.
        $accountId = ReportReadMemo::remember('ga4-binding|'.$tenantId.'|'.$projectId, static fn (): mixed => ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->where('provider', 'ga4')
            ->orderBy('created_at')->value('external_account_id'));

        if (! is_string($accountId) || $accountId === '') {
            return null;
        }

        return ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->find($accountId);
    }

    /** The stored rate to USD for one day of the property's currency, or null — memoised, misses included. */
    private function rate(?string $currency, string $date): ?float
    {
        if ($currency === null) {
            return null;
        }
        if (! array_key_exists($date, $this->rates)) {
            $this->rates[$date] = $this->converter->resolve($currency, 'USD', Carbon::parse($date))['rate'] ?? null;
        }

        return $this->rates[$date];
    }

    /** @return array<string, float> date → revenue in the property's currency */
    private function dailyRevenue(string $tenantId, string $projectId, string $accountId, Carbon $from, Carbon $to): array
    {
        return DB::table('measurement_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->where('metric_key', 'revenue')
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->get(['metric_date', 'value'])
            ->mapWithKeys(static fn (object $r): array => [substr((string) $r->metric_date, 0, 10) => (float) $r->value])
            ->all();
    }

    /**
     * A window's revenue in USD: each day at its own rate. Null when there is no revenue to state, or
     * when a day that HAS revenue has no rate.
     *
     * @param  array<string, float>  $daily
     */
    private function windowUsd(array $daily, ?string $currency, string $from, string $to): ?float
    {
        $sum = 0.0;
        $seen = false;
        foreach ($daily as $date => $value) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $seen = true;
            if ($value == 0.0) {
                continue;
            }
            $rate = $this->rate($currency, $date);
            if ($rate === null) {
                return null;
            }
            $sum += $value * $rate;
        }

        return $seen ? round($sum, 2) : null;
    }

    /** @param  Collection<string, array<string, mixed>>  $byKey */
    private function kpiUsd(string $key, ?float $revenueUsd, $byKey, string $window): ?float
    {
        if ($revenueUsd === null) {
            return null;
        }
        if ($key === 'revenue') {
            return $revenueUsd;
        }
        if ($key === 'aov') {
            $orders = $byKey['transactions'][$window] ?? null;

            // Withheld with no purchases: an average over nothing is not a zero.
            return $orders !== null && $orders > 0 ? round($revenueUsd / $orders, 2) : null;
        }

        return null;
    }

    /**
     * @param  array<string, float>  $daily
     * @param  array{from: string, to: string}  $period
     * @return array<string, mixed>
     */
    private function usdState(?string $currency, array $daily, array $period): array
    {
        if ($currency === null) {
            return ['available' => false, 'reason' => 'no_currency'];
        }
        if ($this->windowUsd($daily, $currency, $period['from'], $period['to']) === null && array_filter($daily) !== []) {
            return ['available' => false, 'reason' => 'no_rate', 'from' => $currency];
        }
        $resolved = $this->converter->resolve($currency, 'USD', Carbon::parse($period['to']));

        return ['available' => $resolved !== null, 'from' => $currency, 'rate' => $resolved['rate'] ?? null, 'rate_date' => $resolved['rate_date'] ?? null, 'source' => $resolved['source'] ?? null]
            + ($resolved === null ? ['reason' => 'no_rate'] : []);
    }

    /**
     * @param  array{from: string, to: string}  $period
     * @return list<array<string, mixed>>
     */
    private function series(string $tenantId, string $projectId, string $accountId, ?string $currency, array $period): array
    {
        $days = [];
        $rows = DB::table('measurement_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->whereIn('metric_key', ['sessions', 'transactions', 'revenue'])
            ->whereBetween('metric_date', [$period['from'], $period['to']])
            ->orderBy('metric_date')
            ->get(['metric_date', 'metric_key', 'value']);

        foreach ($rows as $r) {
            $date = substr((string) $r->metric_date, 0, 10);
            $days[$date] ??= ['date' => $date, 'sessions' => null, 'purchases' => null, 'revenue' => null, 'revenue_usd' => null];
            $key = $r->metric_key === 'transactions' ? 'purchases' : (string) $r->metric_key;
            $days[$date][$key] = round((float) $r->value, 2);
            if ($key === 'revenue') {
                $rate = (float) $r->value == 0.0 ? 1.0 : $this->rate($currency, $date);
                $days[$date]['revenue_usd'] = $rate === null ? null : round((float) $r->value * $rate, 2);
            }
        }

        return array_values($days);
    }

    /**
     * Rows of one session breakdown summed over the window per dimension value, revenue also in USD
     * (each day at its own rate; one missing rate withholds the row's USD figure).
     *
     * @param  list<array{date: string, d1: string, d2: string, m: array<string, mixed>}>  $rows
     * @param  callable(array<string, mixed>): string  $keyOf
     * @return list<array<string, mixed>>
     */
    private function aggregate(array $rows, ?string $currency, callable $keyOf): array
    {
        $agg = [];
        foreach ($rows as $row) {
            $key = $keyOf($row);
            $a = $agg[$key] ?? ['d1' => $row['d1'], 'd2' => $row['d2'], 'revenue_usd' => 0.0, 'usd_complete' => true];
            foreach (Ga4Breakdowns::ADDITIVE as $k) {
                if (array_key_exists($k, $row['m'])) {
                    $a[$k] = ($a[$k] ?? 0.0) + (float) $row['m'][$k];
                }
            }
            $revenue = (float) ($row['m']['revenue'] ?? 0);
            if ($revenue != 0.0) {
                $rate = $this->rate($currency, $row['date']);
                $rate === null ? $a['usd_complete'] = false : $a['revenue_usd'] += $revenue * $rate;
            }
            $agg[$key] = $a;
        }

        return array_values(array_map(static function (array $a): array {
            $a['revenue_usd'] = $a['usd_complete'] && array_key_exists('revenue', $a) ? round($a['revenue_usd'], 2) : null;
            unset($a['usd_complete']);

            return $a;
        }, $agg));
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows  aggregated rows, or null when the property never returned the breakdown
     * @param  callable(array<string, mixed>): array{name: string, sub: ?string}  $name
     * @return array{rows: list<array<string, mixed>>, total: int}|null
     */
    private function sessionTable(?array $rows, callable $name): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        $list = collect($rows)->map(static function (array $a) use ($name): array {
            $sessions = (float) ($a['sessions'] ?? 0);

            return $name($a) + [
                'sessions' => round($sessions, 2),
                'engagement_rate' => $sessions > 0 && isset($a['engaged_sessions']) ? round($a['engaged_sessions'] / $sessions, 4) : null,
                'purchases' => isset($a['purchases']) ? round((float) $a['purchases'], 2) : null,
                'conversion_rate' => $sessions > 0 && isset($a['purchases']) ? round($a['purchases'] / $sessions, 4) : null,
                'revenue' => isset($a['revenue']) ? round((float) $a['revenue'], 2) : null,
                'revenue_usd' => $a['revenue_usd'],
            ];
        })->sortBy([['sessions', 'desc'], ['name', 'asc']])->values();

        return ['rows' => $list->take(self::TABLE_ROWS)->all(), 'total' => $list->count()];
    }

    /**
     * @param  list<array{date: string, d1: string, m: array<string, mixed>}>|null  $rows
     * @return array{rows: list<array<string, mixed>>, total: int}|null
     */
    private function productTable(?array $rows, ?string $currency): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        $agg = [];
        foreach ($rows as $row) {
            $a = $agg[$row['d1']] ?? ['name' => $row['d1'], 'views' => null, 'carts' => null, 'purchased' => null, 'revenue' => null, 'usd' => 0.0, 'usd_complete' => true];
            foreach (['items_viewed' => 'views', 'items_added_to_cart' => 'carts', 'items_purchased' => 'purchased', 'item_revenue' => 'revenue'] as $stored => $shown) {
                if (array_key_exists($stored, $row['m'])) {
                    $a[$shown] = ($a[$shown] ?? 0.0) + (float) $row['m'][$stored];
                }
            }
            $revenue = (float) ($row['m']['item_revenue'] ?? 0);
            if ($revenue != 0.0) {
                $rate = $this->rate($currency, $row['date']);
                $rate === null ? $a['usd_complete'] = false : $a['usd'] += $revenue * $rate;
            }
            $agg[$row['d1']] = $a;
        }
        $list = collect($agg)->map(static fn (array $a): array => [
            'name' => $a['name'],
            'views' => $a['views'] === null ? null : round($a['views'], 2),
            'carts' => $a['carts'] === null ? null : round($a['carts'], 2),
            'purchased' => $a['purchased'] === null ? null : round($a['purchased'], 2),
            'revenue' => $a['revenue'] === null ? null : round($a['revenue'], 2),
            'revenue_usd' => $a['usd_complete'] && $a['revenue'] !== null ? round($a['usd'], 2) : null,
        ])->sortBy([['revenue', 'desc'], ['name', 'asc']])->values();

        return ['rows' => $list->take(self::TABLE_ROWS)->all(), 'total' => $list->count()];
    }

    /**
     * A one-dimension breakdown as donut slices: sessions, purchases, revenue and each slice's share
     * of the visits. Null when the property never returned the breakdown — absent, not an empty ring.
     *
     * WEBSITE-TOTALS-001 — the slices ALWAYS sum to the headline visits (`$total`):
     *   - short of it (the breakdown was synced for fewer days, or GA4 left sessions out): an explicit
     *     `unclassified` slice carries the remainder, with no purchases or revenue of its own;
     *   - over it (GA4 restates the two reports at different times): the sessions are scaled down
     *     proportionally and rounded to whole visits by largest remainder, so the sum is exact. The
     *     purchases and revenue stay the rows' own.
     * With no headline figure the slices stand on their own sum, as before.
     *
     * @param  list<array{date: string, d1: string, d2: string, m: array<string, mixed>}>|null  $rows
     * @return list<array<string, mixed>>|null
     */
    private function slices(?array $rows, ?string $currency, ?float $total = null): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        $agg = $this->aggregate($rows, $currency, static fn (array $r): string => $r['d1']);
        $sum = array_sum(array_map(static fn (array $a): float => (float) ($a['sessions'] ?? 0), $agg));
        $total ??= $sum;

        $items = collect($agg)->map(static fn (array $a): array => [
            'key' => $a['d1'],
            'sessions' => round((float) ($a['sessions'] ?? 0), 2),
            'purchases' => isset($a['purchases']) ? round((float) $a['purchases'], 2) : null,
            'revenue' => isset($a['revenue']) ? round((float) $a['revenue'], 2) : null,
            'revenue_usd' => $a['revenue_usd'],
        ])->sortBy([['sessions', 'desc'], ['key', 'asc']])->values()->all();

        if ($sum > $total && $sum > 0) {
            $items = $this->rescaled($items, $total);
        } elseif ($sum < $total) {
            $items[] = ['key' => 'unclassified', 'sessions' => round($total - $sum, 2), 'purchases' => null, 'revenue' => null, 'revenue_usd' => null];
        }

        return array_map(static fn (array $i): array => $i + ['share' => $total > 0 ? round($i['sessions'] / $total, 4) : null], $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function rescaled(array $items, float $total): array
    {
        $sum = array_sum(array_column($items, 'sessions'));
        $target = (int) round($total);
        $exact = array_map(static fn (array $i): float => $i['sessions'] / $sum * $target, $items);
        $floor = array_map(static fn (float $e): int => (int) floor($e), $exact);
        $left = $target - array_sum($floor);
        $order = array_keys($exact);
        usort($order, static fn (int $x, int $y): int => ($exact[$y] - $floor[$y]) <=> ($exact[$x] - $floor[$x]) ?: $x <=> $y);
        foreach (array_slice($order, 0, max(0, $left)) as $i) {
            $floor[$i]++;
        }
        foreach ($items as $i => $item) {
            $items[$i]['sessions'] = (float) $floor[$i];
        }

        return $items;
    }

    /**
     * What closed (or did not close) a breakdown against the visits: the figure, what the breakdown held, the remainder,
     * whether it was scaled, and on how many of the window's days it had rows.
     *
     * @param  list<array{date: string, d1: string, d2: string, m: array<string, mixed>}>|null  $rows
     * @return array<string, mixed>|null
     */
    private function sliceMeta(?array $rows, ?string $currency, ?float $total, int $daysTotal): ?array
    {
        if ($rows === null || $rows === []) {
            return null;
        }
        $sum = 0.0;
        $dates = [];
        foreach ($rows as $r) {
            $sum += (float) ($r['m']['sessions'] ?? 0);
            $dates[$r['date']] = true;
        }
        $total ??= $sum;

        return [
            'total' => round($total, 2),
            'classified' => round(min($sum, $total), 2),
            'unclassified' => round(max(0.0, $total - $sum), 2),
            'rescaled' => $sum > $total,
            'days_covered' => min(count($dates), max($daysTotal, 1)),
            'days_total' => $daysTotal,
        ];
    }

    /** @param  array{from: string, to: string}  $period */
    private function sessionDays(string $tenantId, string $projectId, string $accountId, array $period): int
    {
        return (int) DB::table('measurement_daily_metrics')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $accountId)
            ->where('metric_key', 'sessions')->whereBetween('metric_date', [$period['from'], $period['to']])
            ->distinct()->count('metric_date');
    }

    /**
     * Sessions → add-to-carts → checkouts → purchases. Cart and checkout are EVENT counts, so a step
     * can exceed the one before it; that is said by `exceeds_previous`, not drawn as a widening funnel.
     *
     * WEBSITE-TOTALS-001 — the stages are the tiles' own daily figures (sessions, add_to_carts, checkouts, transactions), so the
     * funnel and the tiles cannot disagree; the breakdown rows only stand in for a stage whose tile is absent.
     *
     * @param  list<array{m: array<string, mixed>}>  $rows
     * @param  Collection<string, array<string, mixed>>  $byKey
     * @return list<array<string, mixed>>
     */
    private function funnel(array $rows, $byKey): array
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach (['sessions', 'add_to_carts', 'checkouts', 'purchases'] as $k) {
                if (array_key_exists($k, $row['m'])) {
                    $totals[$k] = ($totals[$k] ?? 0.0) + (float) $row['m'][$k];
                }
            }
        }
        $tileKey = ['sessions' => 'sessions', 'add_to_carts' => 'add_to_carts', 'checkouts' => 'checkouts', 'purchases' => 'transactions'];
        $steps = [];
        $previous = null;
        foreach (['sessions', 'add_to_carts', 'checkouts', 'purchases'] as $key) {
            $tile = $byKey[$tileKey[$key]]['current'] ?? null;
            $count = $tile !== null ? round((float) $tile, 2) : (isset($totals[$key]) ? round($totals[$key], 2) : null);
            $steps[] = [
                'stage' => $key,
                'count' => $count,
                'step_rate' => $previous !== null && $previous > 0 && $count !== null ? round($count / $previous, 4) : null,
                'exceeds_previous' => $previous !== null && $count !== null && $count > $previous,
            ];
            $previous = $count;
        }

        return $steps;
    }

    /**
     * The platforms' own figures with their revenue also in USD, at the window's last day's rate —
     * a platform total has no days left to convert one by one. No rate withholds the USD figure.
     *
     * @param  array<string, array{conversions: ?float, revenue: ?float, currency: ?string}>  $reported
     * @return array<string, array{conversions: ?float, revenue: ?float, currency: ?string, revenue_usd: ?float}>
     */
    private function reportedUsd(array $reported, Carbon $to): array
    {
        $out = [];
        foreach ($reported as $platform => $r) {
            $usd = null;
            if ($r['revenue'] !== null && $r['currency'] !== null && $r['currency'] !== '') {
                $rate = $this->converter->resolve((string) $r['currency'], 'USD', $to)['rate'] ?? null;
                $usd = $rate === null ? null : $r['revenue'] * $rate;
            }
            $out[$platform] = ['conversions' => $r['conversions'], 'revenue' => $r['revenue'], 'currency' => $r['currency'], 'revenue_usd' => $usd];
        }

        return $out;
    }
}
