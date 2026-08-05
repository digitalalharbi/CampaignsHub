<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Contracts;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\ValueObjects\HealthResult;
use App\Domains\Integrations\ValueObjects\SyncResult;

/**
 * COMMERCE-001 — what a store platform can be asked, deliberately NOT the advertising contract.
 *
 * Salla and Zid are not advertising platforms and never will be. Connecting one discovers a STORE and
 * its orders, not an ad account and its campaigns; there are no ad sets, no creatives, no impressions
 * and no daily insight rows. Bending them into `AdvertisingConnector` would mean six methods that
 * throw and two that work, and every caller branching on which is which — the flattening that
 * `ProviderCatalogue` exists to prevent, moved one layer down.
 *
 * What the two contracts DO share lives in `ProviderCatalogue`, `PlatformCredentials`, `PlatformOAuth`,
 * `TokenVault`, `PlatformHttp` and the webhook receiver: the parts that are genuinely the same
 * question — may we call this provider, is the token alive, what did it actually say.
 *
 * Every method returns rows in this product's shape, not the platform's. Two Saudi commerce APIs
 * agree on almost nothing, including whether an order's total includes shipping.
 */
interface CommerceConnector
{
    /** Stable machine key: `salla`, `zid`. */
    public function key(): string;

    public function label(): string;

    public function status(): ConnectorStatus;

    public function healthCheck(): HealthResult;

    /**
     * The store(s) the authorised merchant owns.
     *
     * Both platforms authorise ONE store per consent, so this normally returns exactly one row. It is
     * still a list, because "the merchant authorised us" and "we found a store" are different facts
     * and an empty list is the honest way to say the second did not happen.
     *
     * @return list<array{external_id:string,name:string,domain:?string,currency:?string,timezone:?string,status:string,plan:?string,raw:array<string,mixed>}>
     */
    public function fetchStores(): array;

    /** @return SyncResult products, one row per product */
    public function syncProducts(string $storeId): SyncResult;

    /**
     * Orders placed in a window, each carrying its items, its customer and whatever attribution the
     * store recorded at checkout.
     */
    public function syncOrders(string $storeId, string $from, string $to): SyncResult;

    /** @return SyncResult customers, one row per customer */
    public function syncCustomers(string $storeId, string $from, string $to): SyncResult;

    /**
     * Carts abandoned in a window.
     *
     * A platform that does not expose them returns a FAILED result naming that fact, rather than an
     * empty success — «this store has no abandoned carts» and «this platform will not tell us» are
     * different answers, and only the first belongs on a funnel.
     */
    public function syncAbandonedCarts(string $storeId, string $from, string $to): SyncResult;
}
