<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Measurement;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Metrics\Services\CurrencyConverter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * GA4-CLASSIFICATION-001 — «تصنيف»: the same traffic grouped by one chosen axis.
 *
 *   channel · source_medium · platform · campaign · landing_page · product · product_category
 *   · device · country · user_type
 *
 * One control, one shape: each group answers the same columns (or, for products, the item columns), as
 * a server-paged table with a fixed page size, a share of the whole and a USD figure beside the property
 * currency. Filters (platform, channel, campaign) apply where GA4's STORED rows are joint enough to
 * honour them; where they are not (device / country / new-vs-returning / products carry one dimension
 * each) the filter is reported back in `unsupported` and NOT applied — a filter that silently did
 * nothing would read as «this segment has the same traffic».
 */
final class Ga4Classification
{
    public const GROUPS = ['channel', 'source_medium', 'platform', 'campaign', 'landing_page', 'product', 'product_category', 'device', 'country', 'user_type'];

    public const PAGE_SIZES = [10, 25, 50];

    private const SESSION_SUMS = ['sessions', 'engaged_sessions', 'purchases', 'revenue'];

    private const ITEM_SUMS = ['items_viewed', 'items_added_to_cart', 'items_purchased', 'item_revenue'];

    /** group → [stored breakdown, filters it can honour] */
    private const SOURCES = [
        'channel' => ['default_channel_group', ['channel']],
        'source_medium' => ['source_medium', ['platform']],
        'platform' => ['source_medium', ['platform']],
        'campaign' => ['campaign', ['platform', 'campaign']],
        'landing_page' => ['landing_page', []],
        'product' => ['product', []],
        'product_category' => ['product_category', []],
        'device' => ['device', []],
        'country' => ['country', []],
        'user_type' => ['user_type', []],
    ];

    /** @var array<string, ?float> */
    private array $rates = [];

    public function __construct(private readonly Ga4PlatformGap $gap, private readonly CurrencyConverter $converter) {}

    /**
     * @param  array{group: string, page?: int, per_page?: int, platform?: ?string, channel?: ?string, campaign?: ?string, sort?: string, dir?: string}  $o
     * @return array<string, mixed>
     */
    public function page(string $tenantId, string $projectId, Carbon $from, Carbon $to, array $o): array
    {
        $group = $o['group'];
        if (! in_array($group, self::GROUPS, true)) {
            throw new InvalidArgumentException('Unknown group.');
        }
        $perPage = in_array((int) ($o['per_page'] ?? 10), self::PAGE_SIZES, true) ? (int) ($o['per_page'] ?? 10) : 10;
        $page = max(1, (int) ($o['page'] ?? 1));
        $items = in_array($group, ['product', 'product_category'], true);
        $sumKeys = $items ? self::ITEM_SUMS : self::SESSION_SUMS;
        $sort = in_array($o['sort'] ?? '', [...$sumKeys, 'label'], true) ? $o['sort'] : ($items ? 'item_revenue' : 'sessions');
        $dir = strtolower($o['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $base = ['group' => $group, 'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'page' => $page, 'per_page' => $perPage, 'basis' => 'ga4'];
        $property = $this->property($tenantId, $projectId);
        if ($property === null) {
            return $base + ['state' => 'not_connected', 'rows' => [], 'total' => 0, 'pages' => 0, 'columns' => $sumKeys, 'unsupported' => []];
        }

        [$breakdown, $honours] = self::SOURCES[$group];
        $filters = array_filter(['platform' => $o['platform'] ?? null, 'channel' => $o['channel'] ?? null, 'campaign' => $o['campaign'] ?? null], static fn ($v): bool => $v !== null && $v !== '');
        $unsupported = array_values(array_diff(array_keys($filters), $honours));

        // Landing pages under ONE campaign: the drill's last rung reads the joint breakdown.
        if ($group === 'landing_page' && isset($filters['campaign'])) {
            $breakdown = 'campaign_landing';
            $unsupported = array_values(array_diff($unsupported, ['campaign']));
        }

        $q = DB::table('measurement_dimension_rows')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $property->getKey())
            ->where('breakdown', $breakdown)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()]);
        if ($breakdown === 'campaign_landing') {
            $q->where('dimension_1', $filters['campaign']);
        }

        $agg = [];
        foreach ($q->get(['metric_date', 'dimension_1', 'dimension_2', 'metrics']) as $r) {
            $d1 = (string) $r->dimension_1;
            $d2 = (string) $r->dimension_2;
            $label = match ($group) {
                'platform' => $this->platformOfSourceMedium($d1, $d2),
                'source_medium' => $d1.' / '.$d2,
                'campaign' => $d1,
                'landing_page' => $breakdown === 'campaign_landing' ? $d2 : $d1,
                default => $d1,
            };
            if (isset($filters['platform']) && in_array('platform', $honours, true)) {
                $cls = $group === 'campaign' ? $this->platformOfSourceMedium(...array_pad(explode(' / ', $d2, 2), 2, '')) : $this->platformOfSourceMedium($d1, $d2);
                if ($cls !== $filters['platform']) {
                    continue;
                }
            }
            if (isset($filters['campaign']) && $group === 'campaign' && mb_strtolower($d1) !== mb_strtolower((string) $filters['campaign'])) {
                continue;
            }
            $m = (array) json_decode((string) $r->metrics, true);
            $a = $agg[$label] ?? array_fill_keys($sumKeys, 0.0) + ['usd' => 0.0, 'usd_ok' => true];
            foreach ($sumKeys as $k) {
                $a[$k] += (float) ($m[$k] ?? 0);
            }
            $money = (float) ($m[$items ? 'item_revenue' : 'revenue'] ?? 0);
            if ($money != 0.0) {
                $usd = $this->usd($money, $property->currency, substr((string) $r->metric_date, 0, 10));
                $usd === null ? $a['usd_ok'] = false : $a['usd'] += $usd;
            }
            $agg[$label] = $a;
        }

        // The channel filter narrows a campaign / source-medium group through the channel rows' own pairs.
        if (isset($filters['channel']) && $group !== 'channel') {
            $pairs = DB::table('measurement_dimension_rows')
                ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('external_account_id', $property->getKey())
                ->where('breakdown', 'channel_source')->where('dimension_1', $filters['channel'])
                ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
                ->distinct()->pluck('dimension_2')->flip()->all();
            if (in_array('channel', $honours, true)) {
                $agg = array_filter($agg, static fn ($k): bool => isset($pairs[$k]), ARRAY_FILTER_USE_KEY);
            }
        }

        $totalSessions = array_sum(array_column($agg, 'sessions'));
        $rows = [];
        foreach ($agg as $label => $a) {
            $usd = $a['usd_ok'] ? round($a['usd'], 2) : null;
            $row = ['label' => (string) $label] + array_map(static fn (float $v): float => round($v, 2), array_intersect_key($a, array_flip($sumKeys)));
            if (! $items) {
                $row['conversion_rate'] = $a['sessions'] > 0 ? round($a['purchases'] / $a['sessions'], 4) : null;
                $row['aov_usd'] = $usd !== null && $a['purchases'] > 0 ? round($usd / $a['purchases'], 2) : null;
                $row['share'] = $totalSessions > 0 ? round($a['sessions'] / $totalSessions, 4) : null;
            }
            $row['revenue_usd'] = $usd;
            $rows[] = $row;
        }
        usort($rows, static function (array $x, array $y) use ($sort, $dir): int {
            $c = $sort === 'label' ? strcmp($x['label'], $y['label']) : ($x[$sort] <=> $y[$sort]);

            return ($dir === 'asc' ? $c : -$c) ?: strcmp($x['label'], $y['label']);
        });
        $total = count($rows);

        return $base + [
            'state' => $total === 0 ? 'empty' : 'ready',
            'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'total' => $total, 'pages' => (int) max(1, ceil($total / $perPage)),
            'columns' => $sumKeys, 'currency' => $property->currency, 'sort' => $sort, 'dir' => $dir,
            'filters' => $filters, 'unsupported' => $unsupported,
            'last_synced_at' => $property->last_synced_at?->toIso8601String(),
        ];
    }

    private function platformOfSourceMedium(string $source, string $medium): string
    {
        return $this->gap->classOf($source, $medium);
    }

    private function usd(float $amount, ?string $currency, string $date): ?float
    {
        if ($currency === null || $currency === '') {
            return null;
        }
        $k = $currency.'|'.$date;
        if (! array_key_exists($k, $this->rates)) {
            $this->rates[$k] = $this->converter->resolve($currency, 'USD', Carbon::parse($date))['rate'] ?? null;
        }

        return $this->rates[$k] === null ? null : $amount * $this->rates[$k];
    }

    private function property(string $tenantId, string $projectId): ?ExternalAccount
    {
        $accountId = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('is_active', true)->where('provider', 'ga4')
            ->orderBy('created_at')->value('external_account_id');

        return is_string($accountId) && $accountId !== '' ? ExternalAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('account_type', Ga4PropertyDiscovery::ACCOUNT_TYPE)->find($accountId) : null;
    }
}
