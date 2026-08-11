<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Providers;

use App\Domains\Commerce\ValueObjects\Attribution;

/**
 * Salla Admin API v2 (`api.salla.dev/admin/v2`).
 *
 * Two things shape everything below. Money arrives as `{ "amount": 199.5, "currency": "SAR" }` rather
 * than a number, so every total goes through `money()` — reading `amounts.total` as a float yields
 * zero, silently, for every order. And pagination is `pagination.currentPage / totalPages`, so a
 * single-page read would quietly report a busy store's first fifteen orders as its whole month.
 *
 * Awaiting credentials on this install: no app is registered with Salla here, and every response the
 * tests exercise is faked — which proves this parsing and says nothing about their API.
 */
final class SallaConnector extends ApiCommerceConnector
{
    /** Salla refuses a larger page; asking for more returns the default rather than an error. */
    private const PER_PAGE = 50;

    /** A stop the paginator cannot pass, so a broken `totalPages` cannot spin a worker for ever. */
    private const MAX_PAGES = 60;

    protected function platform(): string
    {
        return 'salla';
    }

    public function fetchStores(): array
    {
        $body = $this->read($this->api()->get($this->url('store/info')), 'store information');

        /** @var array<string,mixed> $store */
        $store = (array) ($body['data'] ?? []);

        if (($store['id'] ?? null) === null) {
            return [];
        }

        return [[
            'external_id' => (string) $store['id'],
            'name' => (string) ($store['name'] ?? $store['id']),
            'domain' => isset($store['domain']) ? (string) $store['domain'] : null,
            'currency' => isset($store['currency']) ? (string) $store['currency'] : null,
            'timezone' => isset($store['timezone']) ? (string) $store['timezone'] : null,
            'status' => (string) ($store['status'] ?? 'active'),
            'plan' => isset($store['plan']) ? (string) $store['plan'] : null,
            'raw' => $store,
        ]];
    }

    protected function fetchProducts(string $storeId): array
    {
        $products = [];

        foreach ($this->pages('products', ['per_page' => self::PER_PAGE], 'products') as $p) {
            if (($p['id'] ?? null) === null) {
                continue;
            }

            $products[] = [
                'external_id' => (string) $p['id'],
                'name' => (string) ($p['name'] ?? $p['id']),
                'sku' => isset($p['sku']) ? (string) $p['sku'] : null,
                'status' => (string) ($p['status'] ?? 'active'),
                'price' => $this->money($p['price'] ?? null),
                'currency' => $this->currency($p['price'] ?? null),
                'quantity' => isset($p['quantity']) ? (int) $p['quantity'] : null,
                'category' => $this->firstCategory($p['categories'] ?? null),
                'url' => isset($p['urls']['customer']) ? (string) $p['urls']['customer'] : null,
                'image_url' => isset($p['main_image']) ? (string) $p['main_image'] : null,
                'raw' => (array) $p,
            ];
        }

        return $products;
    }

    protected function fetchOrders(string $storeId, string $from, string $to): array
    {
        $orders = [];

        $query = ['per_page' => self::PER_PAGE, 'from_date' => $from, 'to_date' => $to, 'expanded' => 'true'];

        foreach ($this->pages('orders', $query, 'orders') as $o) {
            if (($o['id'] ?? null) === null) {
                continue;
            }

            /** @var array<string,mixed> $amounts */
            $amounts = (array) ($o['amounts'] ?? []);
            /** @var array<string,mixed> $customer */
            $customer = (array) ($o['customer'] ?? []);

            $orders[] = [
                'external_id' => (string) $o['id'],
                'reference' => isset($o['reference_id']) ? (string) $o['reference_id'] : null,
                'status' => $this->statusSlug($o['status'] ?? null),
                // Salla states the payment side separately from the fulfilment side.
                'payment_status' => isset($o['payment_method']) ? (string) $o['payment_method'] : null,
                'placed_at' => $this->date($o['date'] ?? null),
                'currency' => $this->currency($amounts['total'] ?? null),
                'subtotal' => $this->money($amounts['sub_total'] ?? null),
                'shipping_total' => $this->money($amounts['shipping_cost'] ?? null),
                'tax_total' => $this->money($amounts['tax'] ?? null),
                'discount_total' => $this->money($amounts['discounts'] ?? null),
                'total' => $this->money($amounts['total'] ?? null),
                'customer' => ($customer['id'] ?? null) === null ? null : [
                    'external_id' => (string) $customer['id'],
                    'name' => trim(((string) ($customer['first_name'] ?? '')).' '.((string) ($customer['last_name'] ?? ''))) ?: null,
                    'email' => isset($customer['email']) ? (string) $customer['email'] : null,
                    'phone' => isset($customer['mobile']) ? (string) $customer['mobile'] : null,
                    'city' => isset($customer['city']) ? (string) $customer['city'] : null,
                    'country' => isset($customer['country']) ? (string) $customer['country'] : null,
                ],
                'items' => $this->items($o['items'] ?? null),
                'attribution' => $this->attributionOf($o),
                'raw' => (array) $o,
            ];
        }

        return $orders;
    }

    protected function fetchCustomers(string $storeId, string $from, string $to): array
    {
        $customers = [];

        foreach ($this->pages('customers', ['per_page' => self::PER_PAGE], 'customers') as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            $customers[] = [
                'external_id' => (string) $c['id'],
                'name' => trim(((string) ($c['first_name'] ?? '')).' '.((string) ($c['last_name'] ?? ''))) ?: null,
                'email' => isset($c['email']) ? (string) $c['email'] : null,
                'phone' => isset($c['mobile']) ? (string) $c['mobile'] : null,
                'city' => isset($c['city']) ? (string) $c['city'] : null,
                'country' => isset($c['country']) ? (string) $c['country'] : null,
                'orders_count' => isset($c['orders_count']) ? (int) $c['orders_count'] : null,
                'total_spent' => $this->money($c['total_spent'] ?? null),
                'first_seen_at' => $this->date($c['created_at'] ?? null),
                'raw' => (array) $c,
            ];
        }

        return $customers;
    }

    protected function fetchAbandonedCarts(string $storeId, string $from, string $to): array
    {
        $carts = [];

        foreach ($this->pages('carts/abandoned', ['per_page' => self::PER_PAGE], 'abandoned carts') as $c) {
            if (($c['id'] ?? null) === null) {
                continue;
            }

            /** @var array<string,mixed> $customer */
            $customer = (array) ($c['customer'] ?? []);

            $carts[] = [
                'external_id' => (string) $c['id'],
                'abandoned_at' => $this->date($c['created_at'] ?? $c['updated_at'] ?? null),
                'currency' => $this->currency($c['total'] ?? null),
                'total' => $this->money($c['total'] ?? null),
                'items_count' => isset($c['items']) && is_array($c['items']) ? count($c['items']) : null,
                'customer_external_id' => ($customer['id'] ?? null) === null ? null : (string) $customer['id'],
                'customer_email' => isset($customer['email']) ? (string) $customer['email'] : null,
                'checkout_url' => isset($c['checkout_url']) ? (string) $c['checkout_url'] : null,
                'attribution' => $this->attributionOf($c),
                'raw' => (array) $c,
            ];
        }

        return $carts;
    }

    // ── Salla's own shapes ────────────────────────────────────────────────────────────────────

    /**
     * Walk `pagination.currentPage → totalPages`.
     *
     * Reading only the first page is the failure that looks like success: a store with four hundred
     * orders reports fifty, every figure downstream is a fifth of the truth, and nothing anywhere
     * errors. The hard page cap is a second guard — a provider that returns a nonsensical
     * `totalPages` should cost one worker a minute, not an afternoon.
     *
     * @param  array<string,mixed>  $query
     * @return list<array<string,mixed>>
     */
    private function pages(string $path, array $query, string $what): array
    {
        $rows = [];
        $page = 1;

        do {
            $body = $this->read($this->api()->get($this->url($path), [...$query, 'page' => $page]), $what);

            foreach ((array) ($body['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }

            $total = (int) ($body['pagination']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $total && $page <= self::MAX_PAGES);

        return $rows;
    }

    /**
     * The attribution Salla passed through, and nothing more.
     *
     * Salla has no guaranteed attribution object on an order. When the merchant's storefront preserved
     * the landing URL it is there to parse; when it did not, this returns an empty attribution and the
     * order is honestly «مصدر غير معروف». Inferring a campaign from the referrer domain would turn
     * somebody who typed the address in into a paid conversion.
     *
     * @param  array<string,mixed>  $row
     */
    private function attributionOf(array $row): Attribution
    {
        /** @var array<string,mixed> $source */
        $source = is_array($row['source'] ?? null) ? $row['source'] : [];

        return Attribution::read(
            explicit: [...$source, ...(is_array($row['utm'] ?? null) ? $row['utm'] : [])],
            landingUrl: is_string($row['landing_page'] ?? null)
                ? $row['landing_page']
                : (is_string($source['landing_page'] ?? null) ? $source['landing_page'] : null),
            referrer: is_string($source['referrer'] ?? null) ? $source['referrer'] : null,
        );
    }

    /** @return list<array<string,mixed>> */
    private function items(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item) || ($item['id'] ?? null) === null) {
                continue;
            }

            $rows[] = [
                'external_id' => (string) $item['id'],
                'product_external_id' => isset($item['product']['id']) ? (string) $item['product']['id'] : null,
                'sku' => isset($item['sku']) ? (string) $item['sku'] : null,
                'name' => (string) ($item['name'] ?? $item['id']),
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => $this->money($item['amounts']['price_without_tax'] ?? $item['amounts']['total'] ?? null),
                'total' => $this->money($item['amounts']['total'] ?? null),
            ];
        }

        return $rows;
    }

    /** `{ amount: 199.5, currency: "SAR" }` — reading it as a float gives 0.0, for every order. */
    private function money(mixed $value): ?float
    {
        if (is_array($value)) {
            return isset($value['amount']) ? (float) $value['amount'] : null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function currency(mixed $value): ?string
    {
        return is_array($value) && isset($value['currency']) ? (string) $value['currency'] : null;
    }

    private function statusSlug(mixed $status): string
    {
        if (is_array($status)) {
            return strtolower((string) ($status['slug'] ?? $status['name'] ?? 'unknown'));
        }

        return strtolower((string) ($status ?: 'unknown'));
    }

    /**
     * Salla wraps dates as `{ date: "2026-08-05 10:00:00.000000", timezone: "Asia/Riyadh" }`.
     *
     * ## The wrapper is passed through INTACT — COMMERCE-TZ-001
     *
     * This used to return the string and drop the zone, so `Carbon::parse()` downstream read a
     * merchant's wall clock in the application's own timezone: a sale made at 01:30 in Riyadh was
     * stored as 01:30 UTC, three hours adrift as an instant and wrong on every screen rendering a
     * time for anybody outside Riyadh.
     *
     * The zone belongs to the ROW, not to the store's current setting. A merchant who changed their
     * shop's timezone last month still has orders that were placed under the old one, and this
     * wrapper is the only thing that remembers which. {@see StoreTime} therefore takes the whole
     * object and prefers this zone over anything configured elsewhere.
     *
     * @return array{date: string, timezone: ?string}|string|null
     */
    private function date(mixed $value): array|string|null
    {
        if (is_array($value)) {
            $date = isset($value['date']) ? trim((string) $value['date']) : '';
            $zone = isset($value['timezone']) ? trim((string) $value['timezone']) : '';

            return $date === '' ? null : ['date' => $date, 'timezone' => $zone === '' ? null : $zone];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function firstCategory(mixed $categories): ?string
    {
        if (! is_array($categories)) {
            return null;
        }

        foreach ($categories as $category) {
            if (is_array($category) && isset($category['name'])) {
                return (string) $category['name'];
            }
        }

        return null;
    }
}
