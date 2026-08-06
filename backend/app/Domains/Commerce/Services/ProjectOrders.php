<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Services;

use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Models\ExternalAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * REPORT-OBJECTIVE-005 — the project's orders, loaded ONCE, with one sale counted once.
 *
 * ## The duplicate this exists to remove, and why the database cannot
 *
 * `commerce_orders` is unique on `(external_account_id, external_id)`, which stops a re-sync from
 * inserting the same order twice. It does not stop the case that actually happens: the SAME SHOP
 * connected twice.
 *
 * `external_accounts` is unique on `(provider_connection_id, external_id, account_type)`, and
 * `provider_connections` has no uniqueness at all per tenant and provider — so a merchant who
 * reconnects after a token expiry, or two people on a team who each run the OAuth flow, produce TWO
 * `external_accounts` rows for one shop. Each syncs the same orders under a different account id,
 * both rows satisfy the unique index, and every sale in that shop is now in the ledger twice.
 *
 * Nothing about that is visible in a total. Revenue doubles, order count doubles, AOV stays exactly
 * right — which is the worst combination, because the one figure a reader checks for sanity is the
 * one that looks correct.
 *
 * ## The key, and why it needs the shop in it
 *
 * A duplicate is `(provider, SHOP external id, ORDER external id)`.
 *
 * The shop must be in the key. Two different Salla merchants both number an order `1001`, and a key
 * of `(provider, order id)` would collapse two real orders from two real shops into one — trading a
 * double-count for an undercount, which is not an improvement.
 *
 * ## Which copy survives
 *
 * The one created FIRST. An order's identity must not change the day somebody adds a second
 * connection; a report exported last week and re-exported today has to name the same rows. Sorting
 * by `created_at` then `id` makes the survivor deterministic rather than whatever Postgres returned
 * first.
 *
 * ## Why a loader rather than a filter at each caller
 *
 * §15.17: a surface computing from its own source is an architectural defect. The funnel, the
 * dashboard's store block and the attribution report all need «this project's orders in this
 * window», and three copies of that sentence is three chances for one of them to skip the dedup.
 * There is one copy, here, and what it collapsed is reported rather than silently applied.
 */
final class ProjectOrders
{
    /**
     * The orders of one project in one window, with duplicate copies of a single sale removed.
     *
     * `$from` and `$to` are normalized to whole days by the CALLER — this service does not widen a
     * window, because a service that silently adjusts its own bounds is one no caller can reason
     * about. {@see StoreFunnelService::build()} owns that normalization.
     *
     * @return array{
     *     orders: Collection<int,CommerceOrder>,
     *     duplicates_collapsed: int,
     *     duplicated_shops: list<array{provider:string,shop_external_id:string,connections:int,names:list<string>}>
     * }
     */
    public function forWindow(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        $orders = CommerceOrder::withoutGlobalScopes()
            ->where('project_id', $projectId)
            ->whereBetween('placed_at', [$from, $to])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return ['orders' => $orders, 'duplicates_collapsed' => 0, 'duplicated_shops' => []];
        }

        $shops = $this->shopsOf($tenantId, $orders->pluck('external_account_id')->all());

        $kept = [];
        $seen = [];
        $collapsed = 0;
        $duplicatedShops = [];

        foreach ($orders as $order) {
            $shop = $shops[(string) $order->external_account_id] ?? null;

            /*
             * An order whose store account cannot be read is KEPT, not dropped.
             *
             * Without the shop we cannot prove it is a duplicate, and «I could not check» must never
             * resolve to «delete it». Losing a real sale is a worse failure than showing one twice,
             * and the second is at least visible in a total the merchant recognises.
             */
            if ($shop === null) {
                $kept[] = $order;

                continue;
            }

            $key = $shop['key'].'|'.$order->external_id;

            if (isset($seen[$key])) {
                $collapsed++;
                $duplicatedShops[$shop['key']] = $shop;

                continue;
            }

            $seen[$key] = true;
            $kept[] = $order;
        }

        return [
            'orders' => collect($kept),
            'duplicates_collapsed' => $collapsed,
            'duplicated_shops' => array_values(array_map(
                static fn (array $shop): array => [
                    'provider' => $shop['provider'],
                    'shop_external_id' => $shop['shop_external_id'],
                    'connections' => $shop['connections'],
                    'names' => $shop['names'],
                ],
                $duplicatedShops,
            )),
        ];
    }

    /**
     * account id => the shop it belongs to, with how many accounts point at that same shop.
     *
     * @param  list<string>  $accountIds
     * @return array<string,array{key:string,provider:string,shop_external_id:string,connections:int,names:list<string>}>
     */
    private function shopsOf(string $tenantId, array $accountIds): array
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', array_values(array_unique(array_filter($accountIds))))
            ->get(['id', 'provider', 'external_id', 'name']);

        $byShop = [];

        foreach ($accounts as $account) {
            $key = $account->provider.'|'.$account->external_id;
            $byShop[$key]['provider'] = (string) $account->provider;
            $byShop[$key]['shop_external_id'] = (string) $account->external_id;
            $byShop[$key]['names'][] = (string) $account->name;
            $byShop[$key]['ids'][] = (string) $account->getKey();
        }

        $out = [];

        foreach ($byShop as $key => $shop) {
            foreach ($shop['ids'] as $id) {
                $out[$id] = [
                    'key' => $key,
                    'provider' => $shop['provider'],
                    'shop_external_id' => $shop['shop_external_id'],
                    'connections' => count($shop['ids']),
                    'names' => array_values(array_unique($shop['names'])),
                ];
            }
        }

        return $out;
    }
}
