<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Jobs\SyncStoreJob;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Commerce\Services\StoreSyncer;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * COMMERCE-001 — reading a Salla or Zid store, as that platform actually answers.
 *
 * Every response here is faked, so these prove OUR parsing and say nothing about either API. No
 * install in this repository holds keys for Salla or Zid; both remain **Awaiting Credentials**.
 *
 * The claims worth writing down separately are the ones a shared model would have flattened: Salla
 * states money as an object and paginates, Zid localises every name and publishes no abandoned-cart
 * endpoint at all, and an order that is refunded is not revenue.
 */
final class CommerceStoreSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Shop', 'slug' => 'shop-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    // ── Salla, end to end ─────────────────────────────────────────────────────────────────────

    public function test_salla_products_customers_orders_and_carts_all_land_with_their_own_shapes(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');

        // The rate a real deployment carries. Reporting is USD; this store bills in SAR, and without
        // a rate the money contract correctly refuses to state a USD total at all.
        CurrencyRate::create([
            'base_currency' => 'SAR', 'quote_currency' => 'USD',
            'rate' => 0.2666, 'rate_date' => '2026-07-01', 'source' => 'test',
        ]);

        Http::fake([
            'api.salla.dev/*/products*' => Http::response([
                'data' => [[
                    'id' => 'p1', 'name' => 'قميص', 'sku' => 'SH-1', 'status' => 'sale',
                    // Money is an OBJECT here; reading it as a float gives 0.0 for every product.
                    'price' => ['amount' => 199.5, 'currency' => 'SAR'],
                    'quantity' => 12, 'categories' => [['name' => 'ملابس']],
                ]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
            'api.salla.dev/*/customers*' => Http::response([
                'data' => [['id' => 'c1', 'first_name' => 'سارة', 'last_name' => 'الأحمد', 'email' => 's@example.sa', 'orders_count' => 3]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
            'api.salla.dev/*/orders*' => Http::response([
                'data' => [[
                    'id' => 'o1', 'reference_id' => 1042,
                    'status' => ['slug' => 'completed', 'name' => 'مكتمل'],
                    'date' => ['date' => '2026-08-01 10:00:00.000000', 'timezone' => 'Asia/Riyadh'],
                    'amounts' => [
                        'sub_total' => ['amount' => 199.5, 'currency' => 'SAR'],
                        'shipping_cost' => ['amount' => 15.0, 'currency' => 'SAR'],
                        'total' => ['amount' => 214.5, 'currency' => 'SAR'],
                    ],
                    'customer' => ['id' => 'c1', 'first_name' => 'سارة', 'last_name' => 'الأحمد', 'email' => 's@example.sa'],
                    'items' => [[
                        'id' => 'i1', 'name' => 'قميص', 'quantity' => 1, 'sku' => 'SH-1',
                        'product' => ['id' => 'p1'],
                        'amounts' => ['total' => ['amount' => 199.5, 'currency' => 'SAR']],
                    ]],
                    'landing_page' => 'https://store.sa/p/1?utm_source=snapchat&utm_campaign=ramadan&sclid=SC-9',
                ]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
            'api.salla.dev/*/carts/abandoned*' => Http::response([
                'data' => [['id' => 'ac1', 'created_at' => '2026-08-02 09:00:00', 'total' => ['amount' => 80.0, 'currency' => 'SAR'], 'items' => [1, 2]]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('commerce', $run->type);

        $product = CommerceProduct::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('قميص', $product->name);
        $this->assertSame('199.500000', $product->price);
        $this->assertSame('SAR', $product->currency);
        $this->assertSame($this->project->id, $product->project_id);

        /*
         * MONEY-USD-001 — a SAR store needs a SAR→USD rate before its total can be stated.
         *
         * The reporting currency is USD, and this store bills in SAR. Without a rate the money
         * contract refuses to convert and `total` is null — correct, and the same rule that protects
         * every other figure in the product. It is worth being explicit that the switch to USD cuts
         * both ways: it un-withholds USD ad spend that had no SAR rate, and it withholds SAR store
         * revenue that used to convert at par. Real deployments carry the rate; this fixture now
         * does too.
         */
        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('completed', $order->status);
        $this->assertSame('1042', $order->reference);
        // 214.50 SAR at the stored rate. The ORIGINAL survives beside it — see `original_total`.
        $this->assertSame('57.185700', $order->total);
        $this->assertSame('2026-08-01', $order->placed_at->toDateString());
        $this->assertNotNull($order->commerce_customer_id);

        // The item is joined to the product row the same sweep wrote a moment earlier.
        $item = CommerceOrderItem::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($product->getKey(), $item->commerce_product_id);

        // Attribution read out of the landing URL, both halves of it.
        $this->assertSame('snapchat', $order->utm_source);
        $this->assertSame('ramadan', $order->utm_campaign);
        $this->assertSame('SC-9', $order->click_id);
        $this->assertSame('snapchat', $order->click_id_provider);

        $this->assertSame(1, CommerceCustomer::withoutGlobalScopes()->count());
        $this->assertSame(1, CommerceAbandonedCart::withoutGlobalScopes()->count());
        $this->assertNotNull($store->refresh()->last_synced_at);
    }

    /**
     * Salla paginates, and reading only the first page is the failure that looks like success.
     *
     * A store with four hundred orders would report fifty, every figure downstream would be a fifth of
     * the truth, and nothing anywhere would error.
     */
    public function test_salla_pagination_is_walked_rather_than_stopping_at_the_first_page(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');

        Http::fake([
            'api.salla.dev/*/products*' => Http::sequence()
                ->push(['data' => [['id' => 'p1', 'name' => 'A']], 'pagination' => ['currentPage' => 1, 'totalPages' => 2]])
                ->push(['data' => [['id' => 'p2', 'name' => 'B']], 'pagination' => ['currentPage' => 2, 'totalPages' => 2]]),
            'api.salla.dev/*/customers*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/orders*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/carts/abandoned*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame(2, CommerceProduct::withoutGlobalScopes()->count());
    }

    /** A re-sync of an overlapping window updates in place; it never counts a purchase twice. */
    public function test_syncing_the_same_window_twice_does_not_duplicate_an_order(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->fakeSallaOrder();

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));
        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame(1, CommerceOrder::withoutGlobalScopes()->count());
        $this->assertSame(1, CommerceOrderItem::withoutGlobalScopes()->count());
    }

    // ── Zid, which agrees with Salla about nothing ────────────────────────────────────────────

    /**
     * Zid localises every name, and its store has no abandoned carts to offer.
     *
     * The refusal is the point: an empty SUCCESS would be indistinguishable from a store where nobody
     * abandoned anything, and a funnel built on that claims a perfect checkout rate.
     */
    public function test_zid_reads_localised_names_and_reports_partial_because_it_has_no_abandoned_carts(): void
    {
        $this->configure('zid');
        $store = $this->store('zid');

        Http::fake([
            'api.zid.sa/*/products*' => Http::response([
                'data' => [['id' => 'zp1', 'name' => ['ar' => 'حذاء', 'en' => 'Shoe'], 'price' => 250, 'is_published' => true]],
                'total_count' => 1,
            ]),
            'api.zid.sa/*/customers*' => Http::response(['data' => [], 'total_count' => 0]),
            'api.zid.sa/*/orders*' => Http::response([
                'data' => [[
                    'id' => 'zo1', 'code' => 'Z-77', 'order_status' => ['code' => 'delivered'],
                    'created_at' => '2026-08-02 12:00:00', 'currency_code' => 'SAR',
                    'order_total' => 250,
                    'customer' => ['id' => 'zc1', 'name' => 'خالد', 'email' => 'k@example.sa'],
                    'products' => [['id' => 'zi1', 'product_id' => 'zp1', 'name' => ['ar' => 'حذاء'], 'quantity' => 1, 'total' => 250]],
                ]],
                'total_count' => 1,
            ]),
        ]);

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('partial_mapping', $run->status);
        $this->assertStringContainsString('does not expose abandoned carts', (string) $run->error);

        // The Arabic name, not «Array» and not the English placeholder.
        $this->assertSame('حذاء', CommerceProduct::withoutGlobalScopes()->value('name'));
        $this->assertSame('delivered', CommerceOrder::withoutGlobalScopes()->value('status'));
        $this->assertSame('Z-77', CommerceOrder::withoutGlobalScopes()->value('reference'));
        $this->assertSame(0, CommerceAbandonedCart::withoutGlobalScopes()->count());
    }

    // ── Attribution: the link between an order and the ad that produced it ────────────────────

    /**
     * A UTM naming a campaign we have discovered places the order under it.
     *
     * This is the whole point of the unit — until an order can sit under a campaign, a store's revenue
     * and an ad account's spend are two numbers on two screens.
     */
    public function test_an_order_whose_utm_names_a_known_campaign_is_placed_under_it(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $campaign = $this->knownCampaign('snapchat', 'snap-cmp-1', 'Ramadan Sale');

        $this->fakeSallaOrderWithLanding('https://store.sa/?utm_source=snapchat&utm_campaign=snap-cmp-1&sclid=SC-1');

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($campaign->getKey(), $order->external_campaign_id);
        $this->assertSame($campaign->unified_campaign_id, $order->unified_campaign_id);
        $this->assertSame('utm_campaign_id', $order->attribution_method);
        $this->assertNotNull($order->attributed_at);
    }

    /**
     * A Meta click id beside a Snapchat campaign is a mislabelled link, not a puzzle.
     *
     * It happens constantly when an agency copies a UTM template between platforms. Attributing it to
     * either would put one platform's revenue on another's report, so it is recorded as a conflict —
     * visible, countable, and fixable by the person who built the link.
     */
    public function test_a_click_id_and_a_utm_from_different_platforms_attribute_to_neither(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->knownCampaign('snapchat', 'snap-cmp-1', 'Ramadan Sale');

        $this->fakeSallaOrderWithLanding('https://store.sa/?utm_campaign=snap-cmp-1&fbclid=FB-9');

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($order->external_campaign_id);
        $this->assertSame('conflict', $order->attribution_method);
        // The evidence is kept, so the mislabelled link can be found and fixed.
        $this->assertSame('FB-9', $order->click_id);
        $this->assertSame('snap-cmp-1', $order->utm_campaign);
    }

    /**
     * A click id alone proves the PLATFORM and not the campaign, and the record says exactly that.
     *
     * Resolving a click id to a campaign needs the platform's own click-lookup, which none of the six
     * offers for this purpose. Picking the project's only campaign would be the most flattering lie
     * available.
     */
    public function test_a_click_id_alone_attributes_a_platform_and_never_a_campaign(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->knownCampaign('snapchat', 'snap-cmp-1', 'Ramadan Sale');

        $this->fakeSallaOrderWithLanding('https://store.sa/?sclid=SC-77');

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($order->external_campaign_id);
        $this->assertSame('snapchat', $order->click_id_provider);
        $this->assertSame('click_id_platform_only', $order->attribution_method);
    }

    /** An order with nothing usable is «مصدر غير معروف» — never the project's only campaign. */
    public function test_an_order_with_no_signal_is_attributed_to_nothing_at_all(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->knownCampaign('snapchat', 'snap-cmp-1', 'Ramadan Sale');

        $this->fakeSallaOrder();

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $order = CommerceOrder::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($order->external_campaign_id);
        $this->assertNull($order->utm_source);
        $this->assertSame('none', $order->attribution_method);
        // Stamped anyway: «we looked and found nothing» is not «we have not looked».
        $this->assertNotNull($order->attributed_at);
    }

    /**
     * An order imported before its campaign was discovered is placed on the next sweep.
     *
     * Resolving once at import and never again would leave those orders unattributed for ever — and
     * the two sweeps genuinely race, because a store sync and a structure sync run on different clocks.
     */
    public function test_an_order_imported_before_its_campaign_existed_is_placed_on_the_next_sync(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->fakeSallaOrderWithLanding('https://store.sa/?utm_campaign=snap-cmp-1');

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));
        $this->assertNull(CommerceOrder::withoutGlobalScopes()->value('external_campaign_id'));

        $campaign = $this->knownCampaign('snapchat', 'snap-cmp-1', 'Ramadan Sale');

        app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame($campaign->getKey(), CommerceOrder::withoutGlobalScopes()->value('external_campaign_id'));
    }

    // ── Money that is no longer the merchant's ────────────────────────────────────────────────

    public function test_a_refunded_order_keeps_its_total_and_reports_only_what_the_merchant_kept(): void
    {
        $order = new CommerceOrder(['total' => 500, 'refunded_total' => 200]);
        $this->assertSame(300.0, $order->netRevenue());

        $cancelled = new CommerceOrder(['total' => 500, 'cancelled_at' => Carbon::now()]);
        $this->assertSame(0.0, $cancelled->netRevenue());
    }

    // ── Honest refusals ───────────────────────────────────────────────────────────────────────

    /** An unconfigured store calls nothing. §8 gives that outcome the word `failed`. */
    public function test_an_unconfigured_store_platform_calls_nothing_and_records_a_failed_run(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $run = app(StoreSyncer::class)->sync($this->store('salla'), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->records);
        Http::assertNothingSent();
    }

    public function test_what_the_store_said_is_retained_beside_what_we_made_of_it(): void
    {
        $this->configure('salla');
        $store = $this->store('salla');
        $this->fakeSallaOrder();

        $run = app(StoreSyncer::class)->sync($store, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-05'));

        $raw = IntegrationRawPayload::withoutGlobalScopes()->where('resource', 'commerce')->get();
        $this->assertGreaterThan(0, $raw->count());
        $this->assertSame($run->getKey(), $raw->first()->sync_run_id);
        $this->assertSame('2026-08-01', $raw->first()->window_start->toDateString());
    }

    // ── The sweep ─────────────────────────────────────────────────────────────────────────────

    public function test_the_sweep_queues_only_stores_behind_a_connected_authorisation(): void
    {
        Queue::fake();

        $this->store('salla');

        $revoked = $this->store('zid');
        ProviderConnection::withoutGlobalScopes()->whereKey($revoked->provider_connection_id)->update(['status' => 'revoked']);

        $this->artisan('commerce:sync')->assertSuccessful();

        Queue::assertPushed(SyncStoreJob::class, 1);
    }

    /** An ad account is not a store, and the store sweep must not pick one up. */
    public function test_the_sweep_ignores_ad_accounts(): void
    {
        Queue::fake();

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );

        ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_1',
            'name' => 'Ads', 'status' => 'active',
        ]);

        $this->artisan('commerce:sync')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("commerce_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function store(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30), raw: ['authorization' => 'MANAGER']),
            connectionName: $provider,
        );

        $store = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'store',
            'external_id' => "store_{$provider}",
            'name' => ucfirst($provider).' store',
            'currency' => 'SAR',
            'status' => 'active',
        ]);

        /*
         * Assigned to the project — COMMERCE-PROJECT-001.
         *
         * `StoreSyncer` used to answer «which project?» with the tenant's OLDEST project, so this
         * fixture worked without ever saying where the store's revenue belonged. It refuses now, the
         * same way the ad-platform syncers do, and these tests are about what a store's data DOES
         * once somebody has said which project it feeds — so the fixture has to say it.
         */
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(),
            'provider' => $provider,
            'purpose' => 'ecommerce',
            'is_active' => true,
        ]);

        return $store;
    }

    /**
     * A campaign the project has already discovered, so an order's UTM has something to match.
     *
     * It hangs off its own AD account — a campaign belongs to an ad account, never to the store whose
     * orders it produced, and wiring it to the store would have made the attribution test pass for the
     * wrong reason.
     */
    private function knownCampaign(string $provider, string $externalId, string $name): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        $adAccount = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => "act_{$provider}",
            'name' => ucfirst($provider),
            'status' => 'active',
        ]);

        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'objective' => 'sales', 'status' => 'active',
        ]);

        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $adAccount->getKey(),
            'unified_campaign_id' => $unified->id,
            'provider' => $provider,
            'external_id' => $externalId,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function fakeSallaOrderWithLanding(string $landingUrl): void
    {
        Http::fake([
            'api.salla.dev/*/products*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/customers*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/orders*' => Http::response([
                'data' => [[
                    'id' => 'o1',
                    'status' => ['slug' => 'completed'],
                    'date' => ['date' => '2026-08-01 10:00:00.000000'],
                    'amounts' => ['total' => ['amount' => 100.0, 'currency' => 'SAR']],
                    'landing_page' => $landingUrl,
                ]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
            'api.salla.dev/*/carts/abandoned*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);
    }

    private function fakeSallaOrder(): void
    {
        Http::fake([
            'api.salla.dev/*/products*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/customers*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
            'api.salla.dev/*/orders*' => Http::response([
                'data' => [[
                    'id' => 'o1', 'reference_id' => 5,
                    'status' => ['slug' => 'completed'],
                    'date' => ['date' => '2026-08-01 10:00:00.000000'],
                    'amounts' => ['total' => ['amount' => 100.0, 'currency' => 'SAR']],
                    'items' => [['id' => 'i1', 'name' => 'x', 'quantity' => 1, 'amounts' => ['total' => ['amount' => 100.0, 'currency' => 'SAR']]]],
                ]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
            'api.salla.dev/*/carts/abandoned*' => Http::response(['data' => [], 'pagination' => ['currentPage' => 1, 'totalPages' => 1]]),
        ]);
    }
}
