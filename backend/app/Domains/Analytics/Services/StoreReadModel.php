<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Support\Source;
use App\Domains\Commerce\Services\ProjectStores;
use App\Domains\Commerce\Services\StoreDepth;
use App\Domains\Commerce\Services\StoreOrderSources;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ANALYTICS-DATA-001 §5 — the project's store (Salla, Zid) in one read: orders, revenue, average basket, products,
 * coupons, abandoned carts, and the orders tied to campaigns by their UTM and order source.
 *
 * Built on the integrations that exist: {@see StoreDepth} for the ledger's own reading and {@see StoreOrderSources}
 * for the evidence each order carries. Nothing is recomputed here and nothing is estimated.
 *
 * ## States, honestly
 *
 *  - `not_linked`  — no store is bound to this project. `status` says WHY: `AWAITING_CREDENTIALS` when the platform's
 *    own app for Salla / Zid is not configured yet (the app approval is not complete, so no merchant can link), or
 *    `AWAITING_LINK` when the app is ready and this project simply has not linked its store. No figure is returned.
 *  - `empty`       — a store is linked and the window holds no order.
 *  - `ready`.
 */
final class StoreReadModel
{
    private const COMMERCE_PROVIDERS = ['salla', 'zid'];

    public function __construct(
        private readonly StoreDepth $depth,
        private readonly StoreOrderSources $sources,
        private readonly ProjectStores $stores,
    ) {}

    /** @return array<string, mixed> */
    public function build(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $base = [
            'section' => 'store',
            'source' => Source::STORE_CONFIRMED,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'blended_with_other_sources' => false,
        ];

        $linked = $this->stores->forProject($tenantId, $projectId);
        if ($linked->isEmpty()) {
            $apps = $this->apps();

            return $base + [
                'state' => 'not_linked',
                'status' => in_array(false, array_column($apps, 'configured'), true) ? 'AWAITING_CREDENTIALS' : 'AWAITING_LINK',
                'stores' => [],
                'provider_apps' => $apps,
                'kpis' => [], 'products' => null, 'coupons' => [], 'abandoned' => null, 'campaign_ties' => [],
            ];
        }

        $stores = $linked->map(static fn ($s): array => [
            'provider' => (string) $s->provider, 'name' => (string) $s->name, 'currency' => $s->currency,
            'last_synced_at' => $s->last_synced_at?->toIso8601String(), 'source' => Source::STORE_CONFIRMED,
        ])->values()->all();

        $depth = $this->depth->build($tenantId, $projectId, $from, $to);
        if (($depth['state'] ?? null) !== 'ready') {
            return $base + [
                'state' => ($depth['state'] ?? null) === 'empty' ? 'empty' : 'not_linked',
                'status' => 'LINKED_NO_ORDERS',
                'stores' => $stores, 'provider_apps' => $this->apps(),
                'kpis' => [], 'products' => null, 'coupons' => [], 'abandoned' => null, 'campaign_ties' => [],
            ];
        }

        $opts = ['per_page' => 10, 'sort' => 'orders', 'dir' => 'desc'];
        $coupons = $this->sources->sources($tenantId, $projectId, $from, $to, $opts + ['group' => 'coupon']);
        $ties = $this->sources->sources($tenantId, $projectId, $from, $to, $opts + ['group' => 'campaign']);
        $currency = $depth['currency'];
        $sales = $depth['sales'];
        $prev = $depth['orders']['previous_live'];

        return $base + [
            'state' => 'ready',
            'status' => 'LINKED',
            'currency' => $currency,
            'stores' => $stores,
            'basis' => $depth['basis'],
            'kpis' => [
                'orders' => Source::of($depth['orders']['live'], Source::STORE_CONFIRMED, ['previous' => $prev, 'confirmed' => $depth['orders']['confirmed']]),
                'revenue' => Source::of($sales['net']['current'], Source::STORE_CONFIRMED, ['previous' => $sales['net']['previous'], 'change_pct' => $sales['net']['change_pct'], 'currency' => $currency]),
                'aov' => Source::of($depth['averages']['aov'], Source::STORE_CONFIRMED, ['currency' => $currency]),
                'abandoned_carts' => Source::of($depth['abandoned']['carts'], Source::STORE_CONFIRMED, ['value' => $depth['abandoned']['value'], 'currency' => $currency]),
                'conversion' => Source::of($depth['conversion']['value'], $depth['conversion']['visits_source'] ?? Source::STORE_CONFIRMED, ['visits' => $depth['conversion']['visits']]),
            ],
            'trend' => $depth['trend'],
            'products' => ['source' => Source::STORE_CONFIRMED] + $depth['products'],
            'abandoned' => ['source' => Source::STORE_CONFIRMED] + $depth['abandoned'],
            'coupons' => $this->tagged($coupons),
            'campaign_ties' => $this->tagged($ties),
            'coverage' => $depth['coverage'],
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private function tagged(array $page): array
    {
        if (($page['state'] ?? null) !== 'ready') {
            return [];
        }

        return array_map(static fn (array $r): array => $r + ['source' => Source::STORE_CONFIRMED], $page['rows']);
    }

    /** @return list<array{provider: string, configured: bool}> */
    private function apps(): array
    {
        return array_map(static function (string $p): array {
            try {
                $configured = PlatformCredentials::for($p)->isConfigured();
            } catch (Throwable) {
                $configured = false;
            }

            return ['provider' => $p, 'configured' => $configured];
        }, self::COMMERCE_PROVIDERS);
    }
}
