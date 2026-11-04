<?php

declare(strict_types=1);

namespace App\Domains\Reports\Measurement;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Services\OrderSourceEvidence;
use App\Domains\Commerce\Services\ProjectOrders;
use App\Domains\Commerce\Services\StoreDepth;
use App\Domains\Commerce\Services\StoreOrderSources;
use App\Domains\Integrations\Measurement\Ga4TrafficTable;
use App\Domains\Metrics\Services\ReportingTimezone;
use Illuminate\Support\Carbon;

/**
 * STORE-ANALYTICS-DEPTH-001 §3.1 — «تحليلات المتجر والزيارات», the one category a report can show or hide.
 *
 * What a CLIENT may read of the store and the site, for a live link and a snapshot alike. It is built from an
 * allow-list rather than by trimming the operator's reading: totals, product names, the evidence METHOD counts and
 * GA4's channel split. No customer identity, no order reference, no campaign name, no UTM text and no spend can be
 * in it, because nothing copies them in. Rows flagged as demo are left out, so a report never rests on seeded data.
 *
 * Store and site are two blocks, each with its own label and currency, and nothing adds them.
 */
final class StoreAnalyticsSection
{
    private const TOP = 5;

    public function __construct(
        private readonly StoreDepth $depth,
        private readonly StoreOrderSources $sources,
        private readonly ProjectOrders $orders,
        private readonly ReportingTimezone $timezones,
        private readonly SiteMeasurementService $site,
        private readonly Ga4TrafficTable $traffic,
    ) {}

    /**
     * @param  bool  $withEvidence  off for a link narrower than its project: the evidence counts describe the whole shop
     * @return array<string, mixed>|null null when neither a store nor a site has anything for the period
     */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $hideRevenue, bool $withEvidence): ?array
    {
        $store = $this->store($tenantId, $projectId, $from, $to, $hideRevenue, $withEvidence);
        $site = $this->site($tenantId, $projectId, $from, $to, $hideRevenue);

        if ($store === null && $site === null) {
            return null;
        }

        return [
            'title_ar' => 'تحليلات المتجر والزيارات',
            'title_en' => 'Store & traffic analytics',
            'not_summed' => true,
            'note_ar' => 'المتجر وGA4 مصدران مستقلان، لكل منهما نموذجه وعملته. لا يُجمعان ولا يُفترض تطابقهما.',
            'note_en' => 'The store and GA4 are separate sources, each with its own model and currency. They are not added and not assumed to match.',
            'store' => $store,
            'site' => $site,
        ];
    }

    /** @return array<string, mixed>|null */
    private function store(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $hideRevenue, bool $withEvidence): ?array
    {
        $depth = $this->depth->build($tenantId, $projectId, $from, $to, excludeDemo: true);
        if (($depth['state'] ?? null) !== 'ready') {
            return null;
        }

        $product = static fn (array $p): array => ['name' => $p['name'], 'quantity' => $p['quantity'], 'orders' => $p['orders'], 'revenue' => $hideRevenue ? null : $p['revenue']];
        $money = static fn (array $m): array => $hideRevenue ? ['current' => null, 'previous' => null, 'change_pct' => null] : $m;

        return [
            'label_ar' => $depth['basis']['label_ar'],
            'label_en' => $depth['basis']['label_en'],
            'currency' => $hideRevenue ? null : $depth['currency'],
            'demo_excluded' => $depth['basis']['demo_excluded'],
            'orders' => array_intersect_key($depth['orders'], array_flip(['live', 'confirmed', 'cancelled', 'refunded'])),
            'sales' => array_map($money, $depth['sales']),
            'averages' => [
                'aov' => $hideRevenue ? null : $depth['averages']['aov'],
                'items_per_order' => $depth['averages']['items_per_order'],
            ],
            'customers' => array_intersect_key($depth['customers'], array_flip(['new', 'returning', 'repeat_rate'])),
            'top_products' => array_map($product, array_slice($depth['products']['best_by_revenue'], 0, self::TOP)),
            'trend' => array_map(static fn (array $d): array => ['date' => $d['date'], 'orders' => $d['orders'], 'net' => $hideRevenue ? null : $d['net']], $depth['trend']),
            'money_withheld_orders' => $depth['coverage']['orders_with_money_withheld'],
            'evidence' => $withEvidence ? $this->evidence($tenantId, $projectId, $from, $to, $hideRevenue) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function evidence(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $hideRevenue): array
    {
        $window = $this->timezones->window($projectId, $from, $to);
        $orders = $this->orders->forWindow($tenantId, $projectId, $window['from'], $window['to'])['orders']
            ->reject(static fn (CommerceOrder $o): bool => (bool) $o->is_demo);
        $methods = $this->sources->evidenceTotals($orders);

        // INFL-OFF-001 — while the influencers service is withdrawn a client link does not name it.
        if (! config('brand.features.influencers_ugc_enabled') && isset($methods['influencer_coupon'])) {
            $methods['coupon'] = [
                'orders' => ($methods['coupon']['orders'] ?? 0) + $methods['influencer_coupon']['orders'],
                'net_revenue' => ($methods['coupon']['net_revenue'] ?? 0.0) + $methods['influencer_coupon']['net_revenue'],
            ];
            unset($methods['influencer_coupon']);
        }

        $total = max(1, array_sum(array_column($methods, 'orders')));
        $rows = [];
        foreach (OrderSourceEvidence::METHODS as $method) {
            if (isset($methods[$method])) {
                $rows[] = [
                    'method' => $method,
                    'label_ar' => OrderSourceEvidence::LABELS[$method]['ar'],
                    'label_en' => OrderSourceEvidence::LABELS[$method]['en'],
                    'orders' => $methods[$method]['orders'],
                    'share' => round($methods[$method]['orders'] / $total, 4),
                    'net_revenue' => $hideRevenue ? null : round($methods[$method]['net_revenue'], 2),
                ];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function site(string $tenantId, string $projectId, Carbon $from, Carbon $to, bool $hideRevenue): ?array
    {
        $measured = $this->site->build($tenantId, $projectId, $from, $to);
        if ($measured === null) {
            return null;
        }
        $split = $this->traffic->page($tenantId, $projectId, $from, $to, ['breakdown' => 'traffic_class'], all: true);

        return [
            'label_ar' => 'ما قاسه الموقع (GA4)',
            'label_en' => 'Site-measured (GA4)',
            'currency' => $hideRevenue ? null : ($measured['currency'] ?? null),
            'totals' => ['sessions' => $measured['totals']['sessions'] ?? null, 'users' => $measured['totals']['users'] ?? null, 'transactions' => $measured['totals']['transactions'] ?? null, 'revenue' => $hideRevenue ? null : ($measured['totals']['revenue'] ?? null)],
            'engagement_rate' => $measured['rates']['engagement_rate'] ?? null,
            'channels' => array_map(static fn (array $r): array => ['class' => $r['dimension_1'], 'sessions' => $r['sessions'], 'purchases' => $r['purchases']], ($split['state'] ?? null) === 'ready' ? $split['rows'] : []),
        ];
    }
}
