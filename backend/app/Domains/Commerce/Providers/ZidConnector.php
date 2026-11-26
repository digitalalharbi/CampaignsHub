<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Providers;

use App\Domains\Commerce\ValueObjects\Attribution;

/**
 * Zid Merchant API (`api.zid.sa/v1`).
 *
 * Three things that are Zid's and nobody else's:
 *
 * - **Two credentials on every call.** `Authorization: Bearer <authorization>` AND
 *   `X-Manager-Token: <access_token>`. Both come out of one token exchange, and a call with only the
 *   first is refused. Handled once in `ApiCommerceConnector::api()`.
 * - **Names are localised objects**, `{ "ar": "قميص", "en": "Shirt" }`, so reading `name` as a string
 *   yields `Array` in a product table. `text()` resolves them, preferring Arabic — this product's
 *   customers are Saudi merchants and the Arabic name is the one their team recognises.
 * - **Abandoned carts live under `managers/store/abandoned-carts`** (ZID-ABANDONED-CARTS-001). An earlier
 *   reading said Zid published none and refused the sync on purpose; Zid's docs list the endpoint, so the
 *   refusal was wrong and every Zid sync came back partial for no reason.
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
            'domain' => $this->str($store['url'] ?? null),
            'currency' => $this->str($store['currency'] ?? null),
            'timezone' => null,
            'status' => $this->str($store['status'] ?? null) ?? 'active',
            'plan' => isset($store['package']['name']) ? $this->text($store['package']['name']) : null,
            'raw' => $store,
        ]];
    }

    protected function fetchProducts(string $storeId): array
    {
        $products = [];

        // ZID-PRODUCTS-HEADERS-001 — the products endpoints, unlike the managers/* ones, do not find the store from
        // the manager token: Zid's docs require `Store-Id` and `Role: Manager`. Without them Zid answers
        // 401 «No such user» and the store syncs orders with no catalogue.
        foreach ($this->pages('products', [], 'products', ['Store-Id' => $storeId, 'Role' => 'Manager']) as $p) {
            if (($p['id'] ?? null) === null) {
                continue;
            }

            $products[] = [
                'external_id' => (string) $p['id'],
                'name' => $this->text($p['name'] ?? null) ?? (string) $p['id'],
                'sku' => $this->str($p['sku'] ?? null),
                'status' => ($p['is_published'] ?? true) ? 'active' : 'draft',
                'price' => isset($p['price']) ? (float) $p['price'] : null,
                'currency' => $this->str($p['currency'] ?? null),
                'quantity' => isset($p['quantity']) ? (int) $p['quantity'] : null,
                'category' => isset($p['categories'][0]['name']) ? $this->text($p['categories'][0]['name']) : null,
                'url' => $this->str($p['html_url'] ?? null),
                'image_url' => $this->str($p['images'][0]['image']['full_size'] ?? null),
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
                'reference' => $this->str($o['code'] ?? null),
                'status' => strtolower($this->str($o['order_status'] ?? null, ['code', 'name']) ?? 'unknown'),
                'payment_status' => ($this->str($o['payment_status'] ?? null) === null ? null : strtolower((string) $this->str($o['payment_status'] ?? null))),
                'placed_at' => $this->str($o['created_at'] ?? null),
                'currency' => $this->str($o['currency_code'] ?? null),
                'subtotal' => $this->amount($o['products_subtotal'] ?? $o['sub_total'] ?? null),
                'shipping_total' => $this->amount($o['shipping_cost'] ?? null),
                'tax_total' => $this->amount($o['tax_amount'] ?? null),
                'discount_total' => $this->amount($o['discount_amount'] ?? null),
                'total' => $this->amount($o['order_total'] ?? $o['total'] ?? null),
                'customer' => ($customer['id'] ?? null) === null ? null : [
                    'external_id' => (string) $customer['id'],
                    'name' => trim(($this->str($customer['name'] ?? null) ?? '').' '.($this->str($customer['last_name'] ?? null) ?? '')) ?: null,
                    'email' => $this->str($customer['email'] ?? null),
                    'phone' => $this->str($customer['mobile'] ?? null),
                    'city' => $this->str($o['shipping']['address']['city'] ?? null),
                    'country' => $this->str($o['shipping']['address']['country'] ?? null),
                ],
                'items' => $this->items($o['products'] ?? null),
                'attribution' => Attribution::read(
                    explicit: is_array($o['utm'] ?? null) ? $o['utm'] : [],
                    landingUrl: is_string($o['landing_page'] ?? null) ? $o['landing_page'] : null,
                    referrer: is_string($o['referrer'] ?? null) ? $o['referrer'] : null,
                    // Zid names the applied coupon `coupon_code`, or `coupon.code` on the detailed shape.
                    couponCode: is_string($o['coupon_code'] ?? null)
                        ? $o['coupon_code']
                        : (is_array($o['coupon'] ?? null) && is_string($o['coupon']['code'] ?? null) ? $o['coupon']['code'] : null),
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
                'name' => trim(($this->str($c['name'] ?? null) ?? '').' '.($this->str($c['last_name'] ?? null) ?? '')) ?: null,
                'email' => $this->str($c['email'] ?? null),
                'phone' => $this->str($c['mobile'] ?? null),
                'city' => $this->str($c['city'] ?? null),
                'country' => $this->str($c['country'] ?? null),
                'orders_count' => isset($c['orders_count']) ? (int) $c['orders_count'] : null,
                'total_spent' => $this->amount($c['total_spent'] ?? null),
                'first_seen_at' => $this->str($c['created_at'] ?? null),
                'raw' => (array) $c,
            ];
        }

        return $customers;
    }

    /**
     * ZID-ABANDONED-CARTS-001 — `GET v1/managers/store/abandoned-carts` (docs.zid.sa «List Abandoned Carts»).
     *
     * Field names follow Zid's own cart object: `cart_total`, `customer_*`, `products_count`, `url` (the
     * checkout link the merchant can send back), `created_at`. Money is the store's currency; Zid sends the
     * formatted string beside the number, never a currency code, so the currency is left to the store.
     */
    protected function fetchAbandonedCarts(string $storeId, string $from, string $to): array
    {
        $carts = [];

        // ZID-ABANDONED-CARTS-001 — this endpoint requires `page_size` (Production 2026-11-26: HTTP 422
        // «page_size … مطلوب»); `perPage`, which the other Zid lists read, is not enough here.
        foreach ($this->pages('managers/store/abandoned-carts', ['page_size' => self::PER_PAGE], 'abandoned carts') as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $carts[] = [
                'external_id' => (string) $c['id'],
                'abandoned_at' => $this->str($c['created_at'] ?? $c['updated_at'] ?? null),
                'currency' => null,
                'total' => $this->amount($c['cart_total'] ?? null),
                'items_count' => isset($c['products_count']) && is_numeric($c['products_count']) ? (int) $c['products_count'] : null,
                'customer_external_id' => ($c['customer_id'] ?? null) === null ? null : (string) $c['customer_id'],
                'customer_email' => $this->str($c['customer_email'] ?? null),
                'checkout_url' => $this->str($c['url'] ?? null),
                'attribution' => Attribution::read(explicit: [], landingUrl: null, referrer: null),
                'raw' => (array) $c,
            ];
        }

        return $carts;
    }

    // ── Zid's own shapes ──────────────────────────────────────────────────────────────────────

    /**
     * Page-number pagination with a total count.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    /**
     * @param  array<string,string>  $headers  extra headers a particular endpoint requires
     */
    private function pages(string $path, array $query, string $what, array $headers = []): array
    {
        $rows = [];
        $page = 1;

        do {
            $body = $this->read(
                $this->api()->withHeaders($headers)->get($this->url($path), [...$query, 'page' => $page, 'perPage' => self::PER_PAGE, 'page_size' => self::PER_PAGE]),
                $what,
            );

            // Zid names the list after the resource on some endpoints and `data` on others.
            $list = $body['data'] ?? $body['orders'] ?? $body['products'] ?? $body['customers'] ?? $body['abandoned-carts'] ?? $body['abandoned_carts'] ?? $body['carts'] ?? $body['results'] ?? [];

            foreach ((array) $list as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $seen = count($rows);
            $page++;

            // ZID-PRODUCTS-PAGING-001 — Zid pages three ways: `total_count` on managers/*, Django-style
            // `count`/`next`/`results` on products, and a `pagination` object on abandoned carts, where
            // `page_size` is required (≤ 100; products cap it at 50, so 50 is sent everywhere). A `next` link is the page's own answer to «is there more?»; on
            // that listing `count` is not reliably the total (Zid's example: count 15 beside
            // total_products_count 125), so where `next` is present it decides.
            if (array_key_exists('next', $body)) {
                $more = is_string($body['next']) && $body['next'] !== '';
            } elseif (is_array($body['pagination'] ?? null)) {
                // The abandoned-carts listing pages in `pagination` (`next_page`, `last_page`).
                $p = $body['pagination'];
                $more = ($p['next_page'] ?? null) !== null
                    || (isset($p['last_page']) && (int) $p['last_page'] >= $page);
            } else {
                $count = (int) ($body['total_count'] ?? $body['count'] ?? count((array) $list));
                $more = $seen < $count;
            }
        } while ($more && $list !== [] && $page <= self::MAX_PAGES);

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
                'product_external_id' => $this->str($item['product_id'] ?? null),
                'sku' => $this->str($item['sku'] ?? null),
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
