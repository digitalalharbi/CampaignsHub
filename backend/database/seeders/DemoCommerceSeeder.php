<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Ramsey\Uuid\Uuid;

/**
 * DEMO-COMMERCE — the merchant's ledger the demo never had.
 *
 * ## What was missing, and what it cost
 *
 * `COMMERCE-001` shipped the Salla/Zid connectors, the order tables, the attribution resolver and the
 * store half of the funnel. No seeder ever wrote a single order. So on every install:
 *
 *   - «الفانل والمتجر» said «لا يوجد متجر مربوط» on all four store stages;
 *   - the funnel's attribution comparison had nothing on the store side to compare;
 *   - and REPORT-OBJECTIVE-005's Store-Confirmed block — the ONLY figure in this product that may
 *     honestly be totalled — could not be looked at by anybody.
 *
 * Code with no data to run over is code nobody has seen work. This seeder exists so those surfaces
 * can be reviewed live, which is the standing rule for every unit here.
 *
 * ## Everything is DEMO and says so
 *
 * `is_demo = true` on every row, a connection named «(بيانات تجريبية)», and a credential payload that
 * is a labelled placeholder rather than a plausible-looking token. Salla and Zid remain
 * **Awaiting Credentials**; nothing here is or claims to be a real store connection.
 *
 * ## The attribution mix is the point
 *
 * Real stores do not hand over clean attribution, and a demo that pretended otherwise would teach the
 * wrong lesson and leave the product's most important refusals invisible. So the orders are seeded
 * across every case the resolver distinguishes:
 *
 *   - a `utm_campaign` naming a discovered campaign → placed on that campaign;
 *   - a click id and nothing else → the PLATFORM is proven, the campaign is not;
 *   - a click id from one platform beside a UTM from another → `conflict`, attributed to neither;
 *   - nothing at all → `none`, which is «we do not know», not «direct».
 *
 * And the platforms deliberately over-report against it. Each platform claims more conversions than
 * the shop recorded for it, because that is what pixels do, and the gap is the number the attribution
 * page exists to show. A demo where the two agreed would make that page look pointless.
 */
final class DemoCommerceSeeder extends Seeder
{
    private const NS = 'campaignshub-demo-commerce';

    private const DAYS = 90;

    private const CURRENCY = 'SAR';

    /** The shop, as Salla would name it. */
    private const SHOP_EXTERNAL_ID = 'salla_store_774120';

    /**
     * The products a fashion store sells, and what one costs.
     *
     * @var list<array{0:string,1:string,2:float}>
     */
    private const PRODUCTS = [
        ['sku-ab-001', 'عباية سوداء كلاسيكية', 420.0],
        ['sku-ab-002', 'عباية بتطريز ذهبي', 690.0],
        ['sku-sc-010', 'طرحة حرير', 180.0],
        ['sku-bg-004', 'حقيبة يد جلدية', 540.0],
        ['sku-sh-007', 'حذاء كعب متوسط', 380.0],
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->id);

        // The project the analytics demo populated — the one whose funnel a reviewer actually opens.
        $project = Project::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', 'متجر تجريبي — Demo')
            ->first();

        if ($project === null) {
            app(TenantContext::class)->forget();

            return;
        }

        $store = $this->store($tenant, $project);
        $products = $this->products($tenant, $project, $store);
        $campaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->get(['id', 'provider', 'name', 'unified_campaign_id']);

        $this->orders($tenant, $project, $store, $products, $campaigns);
        $this->carts($tenant, $project, $store);

        app(TenantContext::class)->forget();
    }

    /** The Salla store, behind a connection that is labelled demo end to end. */
    private function store(Tenant $tenant, Project $project): ExternalAccount
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->firstOrNew(['id' => $this->uuid('cred')]);

        if (! $credential->exists) {
            $credential->forceFill([
                'id' => $this->uuid('cred'),
                'tenant_id' => $tenant->id,
                'provider' => 'salla',
                'credential_scope' => 'project_only',
                'credential_type' => 'oauth',
                'status' => 'active',
            ]);
            // A labelled placeholder. Never a token shaped like a real one.
            $credential->setPayload('DEMO-PLACEHOLDER-NOT-A-REAL-TOKEN');
            $credential->save();
        }

        $connection = ProviderConnection::withoutGlobalScopes()->updateOrCreate(
            ['id' => $this->uuid('conn')],
            [
                'tenant_id' => $tenant->id,
                'credential_id' => $credential->id,
                'provider' => 'salla',
                'connection_name' => 'سلة — بيانات تجريبية',
                'scope' => 'project_only',
                'status' => 'connected',
                'last_health_check_at' => Carbon::now()->subHours(2),
                'last_successful_sync_at' => Carbon::now()->subHours(2),
            ],
        );

        return ExternalAccount::withoutGlobalScopes()->updateOrCreate(
            ['id' => $this->uuid('acct')],
            [
                'tenant_id' => $tenant->id,
                'client_workspace_id' => $project->client_workspace_id,
                'provider_connection_id' => $connection->id,
                'provider' => 'salla',
                'account_type' => 'store',
                'external_id' => self::SHOP_EXTERNAL_ID,
                'name' => 'متجر العباءات — سلة (تجريبي)',
                'currency' => self::CURRENCY,
                'timezone' => 'Asia/Riyadh',
                'status' => 'active',
                'metadata' => ['is_demo' => true],
                'last_synced_at' => Carbon::now()->subHours(2),
            ],
        );
    }

    /** @return list<CommerceProduct> */
    private function products(Tenant $tenant, Project $project, ExternalAccount $store): array
    {
        $out = [];

        foreach (self::PRODUCTS as $i => [$sku, $name, $price]) {
            $out[] = CommerceProduct::withoutGlobalScopes()->updateOrCreate(
                ['external_account_id' => $store->id, 'external_id' => "p{$i}"],
                [
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'provider' => 'salla',
                    'name' => $name,
                    'sku' => $sku,
                    'status' => 'active',
                    'price' => $price,
                    'currency' => self::CURRENCY,
                    'quantity' => 40 + $i * 7,
                    'category' => 'أزياء',
                    'is_demo' => true,
                    'last_synced_at' => Carbon::now()->subHours(2),
                ],
            );
        }

        return $out;
    }

    /**
     * Ninety days of orders, deterministic — no `rand()`, so the demo reads the same on every install.
     *
     * @param  list<CommerceProduct>  $products
     * @param  Collection<int,ExternalCampaign>  $campaigns
     */
    private function orders(
        Tenant $tenant,
        Project $project,
        ExternalAccount $store,
        array $products,
        Collection $campaigns,
    ): void {
        $start = Carbon::today()->subDays(self::DAYS - 1);

        // One discovered campaign per platform, so a UTM can name something real.
        $byProvider = $campaigns->groupBy('provider')->map(fn ($g) => $g->first());

        $customers = $this->customers($tenant, $project, $store);
        $n = 0;

        for ($d = 0; $d < self::DAYS; $d++) {
            $date = $start->copy()->addDays($d);

            // A weekly rhythm and a gentle upward trend — enough shape to make a chart worth reading.
            $perDay = (int) max(1, round(6 + 3 * sin($d / 5.0) + 2.5 * ($d / self::DAYS)));

            for ($k = 0; $k < $perDay; $k++) {
                $n++;
                $product = $products[$n % count($products)];
                $quantity = 1 + ($n % 2);
                $total = round((float) $product->price * $quantity, 2);

                $attribution = $this->attributionFor($n, $byProvider);

                /*
                 * A refund on roughly one order in twenty, and a cancellation on one in forty.
                 *
                 * Both exist so the store figures are not a clean multiplication a reader could do in
                 * their head: `netRevenue()` subtracts the refund and drops a cancelled order to zero,
                 * and a demo where those code paths never fire is a demo that never exercises them.
                 */
                $refunded = $n % 20 === 0 ? round($total * 0.5, 2) : 0.0;
                $cancelled = $n % 40 === 0;

                $order = CommerceOrder::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->id, 'external_id' => (string) (100_000 + $n)],
                    [
                        'tenant_id' => $tenant->id,
                        'project_id' => $project->id,
                        'commerce_customer_id' => $customers[$n % count($customers)]->id,
                        'provider' => 'salla',
                        'reference' => 'SL-'.(100_000 + $n),
                        'status' => $cancelled ? 'cancelled' : ($d > self::DAYS - 4 ? 'processing' : 'completed'),
                        'payment_status' => $cancelled ? 'refunded' : 'paid',
                        'placed_at' => $date->copy()->addHours(9 + ($n % 12)),
                        'currency' => self::CURRENCY,
                        'subtotal' => $total,
                        'shipping_total' => 25.0,
                        'tax_total' => round($total * 0.15, 2),
                        'discount_total' => 0.0,
                        'total' => $total,
                        'refunded_total' => $refunded,
                        /*
                         * COMMERCE-FX-001 — a demo shop sells in the reporting currency, and the
                         * identity conversion is WRITTEN rather than left blank.
                         *
                         * A monetary row with no currency provenance is indistinguishable from one
                         * whose conversion was withheld, so leaving these null would make the funnel's
                         * coverage block report the demo store as a problem on every install.
                         */
                        'original_currency' => self::CURRENCY,
                        'original_subtotal' => $total,
                        'original_shipping_total' => 25.0,
                        'original_tax_total' => round($total * 0.15, 2),
                        'original_discount_total' => 0.0,
                        'original_total' => $total,
                        'original_refunded_total' => $refunded,
                        'exchange_rate' => 1,
                        'rate_date' => $date->toDateString(),
                        'rate_source' => 'identity',
                        'refunded_at' => $refunded > 0 ? $date->copy()->addDays(3) : null,
                        'cancelled_at' => $cancelled ? $date->copy()->addHours(20) : null,
                        ...$attribution['columns'],
                        'external_campaign_id' => $attribution['campaign']?->id,
                        'unified_campaign_id' => $attribution['campaign']?->unified_campaign_id,
                        'attribution_method' => $attribution['method'],
                        'attributed_at' => Carbon::now()->subHours(2),
                        'is_demo' => true,
                        'last_synced_at' => Carbon::now()->subHours(2),
                    ],
                );

                CommerceOrderItem::withoutGlobalScopes()->updateOrCreate(
                    ['commerce_order_id' => $order->id, 'external_id' => 'i1'],
                    [
                        'tenant_id' => $tenant->id,
                        'commerce_product_id' => $product->id,
                        'product_external_id' => $product->external_id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $product->price,
                        'total' => $total,
                        'original_unit_price' => $product->price,
                        'original_total' => $total,
                    ],
                );
            }
        }
    }

    /**
     * Which attribution signals this order arrived with.
     *
     * The cycle covers every case the resolver distinguishes, in roughly the proportions a real store
     * sees — most orders placeable, a meaningful share not, and one mislabelled link.
     *
     * @param  Collection<string,ExternalCampaign>  $byProvider
     * @return array{columns:array<string,mixed>,campaign:?ExternalCampaign,method:string}
     */
    private function attributionFor(int $n, Collection $byProvider): array
    {
        $providers = ['snapchat', 'tiktok', 'meta', 'google'];
        $provider = $providers[$n % 4];
        $campaign = $byProvider->get($provider);
        $clickIdKey = ['snapchat' => 'sclid', 'tiktok' => 'ttclid', 'meta' => 'fbclid', 'google' => 'gclid'][$provider];

        $case = $n % 10;

        // 0–4: a UTM naming a real campaign, with the platform's own click id beside it.
        if ($case <= 4 && $campaign !== null) {
            return [
                'columns' => [
                    'utm_source' => $provider,
                    'utm_medium' => 'cpc',
                    'utm_campaign' => $campaign->external_id,
                    'click_id' => "{$clickIdKey}_demo_{$n}",
                    'click_id_provider' => $provider,
                    'landing_url' => "https://demo-store.example/?utm_source={$provider}&utm_medium=cpc&utm_campaign={$campaign->external_id}",
                ],
                'campaign' => $campaign,
                'method' => 'utm_campaign_id',
            ];
        }

        // 5–6: a click id and nothing else. The PLATFORM is proven; the campaign is not, and the
        // resolver refuses to guess one — which is the behaviour worth being able to see.
        if ($case <= 6) {
            return [
                'columns' => [
                    'click_id' => "{$clickIdKey}_demo_{$n}",
                    'click_id_provider' => $provider,
                    'landing_url' => "https://demo-store.example/?{$clickIdKey}={$clickIdKey}_demo_{$n}",
                ],
                'campaign' => null,
                'method' => 'click_id_platform_only',
            ];
        }

        // 7: a mislabelled link — Meta's click id beside a Google campaign's UTM. Attributed to
        // NEITHER, and left visible as a number a media buyer can go and fix.
        if ($case === 7) {
            return [
                'columns' => [
                    'utm_source' => 'google',
                    'utm_medium' => 'cpc',
                    'utm_campaign' => $byProvider->get('google')?->external_id,
                    'click_id' => "fbclid_demo_{$n}",
                    'click_id_provider' => 'meta',
                    'landing_url' => 'https://demo-store.example/?utm_source=google&fbclid=demo',
                ],
                'campaign' => null,
                'method' => 'conflict',
            ];
        }

        // 8–9: nothing usable. `none` — never «direct», never «organic», never the only campaign running.
        return ['columns' => [], 'campaign' => null, 'method' => 'none'];
    }

    /** @return list<CommerceCustomer> */
    private function customers(Tenant $tenant, Project $project, ExternalAccount $store): array
    {
        $names = ['نورة', 'سارة', 'ريم', 'لمى', 'هند', 'دانة'];
        $out = [];

        foreach ($names as $i => $name) {
            $out[] = CommerceCustomer::withoutGlobalScopes()->updateOrCreate(
                ['external_account_id' => $store->id, 'external_id' => "c{$i}"],
                [
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'provider' => 'salla',
                    'name' => $name,
                    // Demo contact details, on a domain reserved for documentation. No real person.
                    'email' => "customer{$i}@demo-store.example",
                    'city' => ['الرياض', 'جدة', 'الدمام'][$i % 3],
                    'country' => 'SA',
                    'currency' => self::CURRENCY,
                    'first_seen_at' => Carbon::today()->subDays(self::DAYS - $i * 3),
                    'is_demo' => true,
                    'last_synced_at' => Carbon::now()->subHours(2),
                ],
            );
        }

        return $out;
    }

    /**
     * Abandoned carts, which Salla reports and Zid does not.
     *
     * Seeded on the Salla store precisely so the funnel's «الإضافة للسلة» stage has a real figure and
     * the checkout rate is not a straight 100% — a store losing no carts at all is a store nobody has.
     */
    private function carts(Tenant $tenant, Project $project, ExternalAccount $store): void
    {
        $start = Carbon::today()->subDays(self::DAYS - 1);

        for ($d = 0; $d < self::DAYS; $d++) {
            $date = $start->copy()->addDays($d);
            $perDay = (int) max(1, round(4 + 2 * sin($d / 4.0)));

            for ($k = 0; $k < $perDay; $k++) {
                CommerceAbandonedCart::withoutGlobalScopes()->updateOrCreate(
                    ['external_account_id' => $store->id, 'external_id' => "cart-{$d}-{$k}"],
                    [
                        'tenant_id' => $tenant->id,
                        'project_id' => $project->id,
                        'provider' => 'salla',
                        'abandoned_at' => $date->copy()->addHours(11 + $k),
                        'currency' => self::CURRENCY,
                        'total' => 380.0 + ($k * 60),
                        // The identity conversion, written for the reason the orders above state.
                        'original_currency' => self::CURRENCY,
                        'original_total' => 380.0 + ($k * 60),
                        'exchange_rate' => 1,
                        'rate_date' => $date->toDateString(),
                        'rate_source' => 'identity',
                        'items_count' => 1 + ($k % 3),
                        'is_demo' => true,
                        'last_synced_at' => Carbon::now()->subHours(2),
                    ],
                );
            }
        }
    }

    private function uuid(string $key): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, self::NS.':'.$key);
    }
}
