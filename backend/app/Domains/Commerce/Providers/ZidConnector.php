<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Providers;

use App\Domains\Commerce\ValueObjects\Attribution;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * Zid Merchant API (`api.zid.sa/v1`).
 *
 * Three things that are Zid's and nobody else's:
 *
 * - **Two credentials on every call.** `Authorization: Bearer <access_token>` AND
 *   `X-Manager-Token: <authorization>`. Both come out of one token exchange, and a call with only the
 *   first is refused. Handled once in `ApiCommerceConnector::api()`.
 * - **Names are localised objects**, `{ "ar": "قميص", "en": "Shirt" }`, so reading `name` as a string
 *   yields `Array` in a product table. `text()` resolves them, preferring Arabic — this product's
 *   customers are Saudi merchants and the Arabic name is the one their team recognises.
 * - **No abandoned-cart endpoint.** Zid does not expose them, and that is stated as a refusal rather
 *   than returned as an empty success: «this store had no abandoned carts» and «this platform will
 *   not tell us» are different answers, and a funnel that shows the second as the first invents a
 *   perfect checkout rate.
 *
 * Awaiting credentials on this install; every response the tests exercise is faked.
 */
final class ZidConnector extends ApiCommerceConnector
{
    private const PER_PAGE = 50;

    private const MAX_PAGES = 60;

    protected function platform(): string
    {
        return 'zid';
    }

    public function fetchStores(): array
    {
        $body = $this->read($this->api()->get($this->url('managers/account/profile')), 'store profile');

        /** @var array<string,mixed> $store */
        $store = (array) ($body['user']['store'] ?? $body['data']['store'] ?? []);

        if (($store['id'] ?? null) === null) {
            return [];
        }

        return [[
            'external_id' => (string) $store['id'],
            'name' => $this->text($store['title'] ?? $store['name'] ?? null) ?? (string) $store['id'],
            'domain' => isset($store['url']) ? (string) $store['url'] : null,
            'currency' => isset($store['currency']) ? (string) $store['currency'] : null,
            'timezone' => null,
            'status' => (string) ($store['status'] ?? 'active'),
            'plan' => isset($store['package']['name']) ? $this->text($store['package']['name']) : null,
            'raw' => $store,
        ]];
    }

    protected function fetchProducts(string $storeId): array
    {
        $products = [];

        foreach ($this->pages('products', [], 'products') as $p) {
            if (($p['id'] ?? null) === null) {
                continue;
            }

            $products[] = [
                'external_id' => (string) $p['id'],
                'name' => $this->text($p['name'] ?? null) ?? (string) $p['id'],
                'sku' => isset($p['sku']) ? (string) $p['sku'] : null,
                'status' => ($p['is_published'] ?? true) ? 'active' : 'draft',
                'price' => isset($p['price']) ? (float) $p['price'] : null,
                'currency' => isset($p['currency']) ? (string) $p['currency'] : null,
                'quantity' => isset($p['quantity']) ? (int) $p['quantity'] : null,
                'category' => isset($p['categories'][0]['name']) ? $this->text($p['categories'][0]['name']) : null,
                'url' => isset($p['html_url']) ? (string) $p['html_url'] : null,
                'image_url' => isset($p['images'][0]['image']['full_size']) ? (string) $p['images'][0]['image']['full_size'] : null,
                'raw' => (array) $p,
            ];
        }

        return $products;
    }

    protected function fetchOrders(string $storeId, string $from, string $to): array
    {
        $orders = [];

        foreach ($this->pages('managers/store/orders', ['from' => $from, 'to' => $to], 'orders') as $o) {
            if (($o['id'] ?? null) === null) {
                continue;
            }

            /** @var array<string,mixed> $customer */
            $customer = (array) ($o['customer'] ?? []);

            $orders[] = [
                'external_id' => (string) $o['id'],
                'reference' => isset($o['code']) ? (string) $o['code'] : null,
                'status' => strtolower((string) ($o['order_status']['code'] ?? $o['order_status']['name'] ?? 'unknown')),
                'payment_status' => isset($o['payment_status']) ? strtolower((string) $o['payment_status']) : null,
                'placed_at' => isset($o['created_at']) ? (string) $o['created_at'] : null,
                'currency' => isset($o['currency_code']) ? (string) $o['currency_code'] : null,
                'subtotal' => $this->amount($o['products_subtotal'] ?? $o['sub_total'] ?? null),
                'shipping_total' => $this->amount($o['shipping_cost'] ?? null),
                'tax_total' => $this->amount($o['tax_amount'] ?? null),
                'discount_total' => $this->amount($o['discount_amount'] ?? null),
                'total' => $this->amount($o['order_total'] ?? $o['total'] ?? null),
                'customer' => ($customer['id'] ?? null) === null ? null : [
                    'external_id' => (string) $customer['id'],
                    'name' => trim(((string) ($customer['name'] ?? '')).' '.((string) ($customer['last_name'] ?? ''))) ?: null,
                    'email' => isset($customer['email']) ? (string) $customer['email'] : null,
                    'phone' => isset($customer['mobile']) ? (string) $customer['mobile'] : null,
                    'city' => isset($o['shipping']['address']['city']) ? (string) $o['shipping']['address']['city'] : null,
                    'country' => isset($o['shipping']['address']['country']) ? (string) $o['shipping']['address']['country'] : null,
                ],
                'items' => $this->items($o['products'] ?? null),
                'attribution' => Attribution::read(
                    explicit: is_array($o['utm'] ?? null) ? $o['utm'] : [],
                    landingUrl: is_string($o['landing_page'] ?? null) ? $o['landing_page'] : null,
                    referrer: is_string($o['referrer'] ?? null) ? $o['referrer'] : null,
                ),
                'raw' => (array) $o,
            ];
        }

        return $orders;
    }

    protected function fetchCustomers(string $storeId, string $from, string $to): array
    {
        $customers = [];

        foreach ($this->pages('managers/store/customers', [], 'customers') as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $customers[] = [
                'external_id' => (string) $c['id'],
                'name' => trim(((string) ($c['name'] ?? '')).' '.((string) ($c['last_name'] ?? ''))) ?: null,
                'email' => isset($c['email']) ? (string) $c['email'] : null,
                'phone' => isset($c['mobile']) ? (string) $c['mobile'] : null,
                'city' => isset($c['city']) ? (string) $c['city'] : null,
                'country' => isset($c['country']) ? (string) $c['country'] : null,
                'orders_count' => isset($c['orders_count']) ? (int) $c['orders_count'] : null,
                'total_spent' => $this->amount($c['total_spent'] ?? null),
                'first_seen_at' => isset($c['created_at']) ? (string) $c['created_at'] : null,
                'raw' => (array) $c,
            ];
        }

        return $customers;
    }

    /**
     * Zid publishes no abandoned-cart endpoint.
     *
     * Overridden rather than left to return `[]`, because an empty SUCCESS would be indistinguishable
     * from a store where nobody abandoned anything — and a checkout funnel built on that would show a
     * hundred-percent completion rate to a client who is losing carts every day.
     */
    public function syncAbandonedCarts(string $storeId, string $from, string $to): SyncResult
    {
        return SyncResult::failed($this->label().' does not expose abandoned carts through its API.');
    }

    protected function fetchAbandonedCarts(string $storeId, string $from, string $to): array
    {
        return [];
    }

    // ── Zid's own shapes ──────────────────────────────────────────────────────────────────────

    /**
     * Page-number pagination with a total count.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function pages(string $path, array $query, string $what): array
    {
        $rows = [];
        $page = 1;

        do {
            $body = $this->read(
                $this->api()->get($this->url($path), [...$query, 'page' => $page, 'perPage' => self::PER_PAGE]),
                $what,
            );

            // Zid names the list after the resource on some endpoints and `data` on others.
            $list = $body['data'] ?? $body['orders'] ?? $body['products'] ?? $body['customers'] ?? [];

            foreach ((array) $list as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $count = (int) ($body['total_count'] ?? $body['count'] ?? count((array) $list));
            $seen = count($rows);
            $page++;
        } while ($seen < $count && $list !== [] && $page <= self::MAX_PAGES);

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $products): array
    {
        if (! is_array($products)) {
            return [];
        }

        $rows = [];

        foreach ($products as $item) {
            if (! is_array($item) || ($item['id'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                'external_id' => (string) $item['id'],
                'product_external_id' => isset($item['product_id']) ? (string) $item['product_id'] : null,
                'sku' => isset($item['sku']) ? (string) $item['sku'] : null,
                'name' => $this->text($item['name'] ?? null) ?? (string) $item['id'],
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => $this->amount($item['price'] ?? null),
                'total' => $this->amount($item['total'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * `{ "ar": "قميص", "en": "Shirt" }` → «قميص».
     *
     * Arabic first because the merchants using this product read Arabic, and the English field is
     * frequently the untranslated placeholder rather than a translation.
     */
    private function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['ar', 'en'] as $locale) {
            if (isset($value[$locale]) && is_string($value[$locale]) && $value[$locale] !== '') {
                return $value[$locale];
            }
        }

        return null;
    }

    private function amount(mixed $value): ?float
    {
        if (is_array($value)) {
            return isset($value['amount']) ? (float) $value['amount'] : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
