<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Integrations\Measurement\MeasurementDailyMetric;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AttributionTransparency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ATTRIBUTION-MODELS-001 — first touch, last touch, assists and conversion paths from the evidence
 * the store holds (Owner directive 7). A touch is an order with its own evidence; nothing is split
 * fractionally and nothing unattributed is distributed.
 */
final class AttributionModelComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Mod', 'slug' => 'mod-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    /** A customer Google brought in and Meta brought back: Meta is last touch, Google first touch and an assist. */
    public function test_the_first_order_names_the_first_touch_and_the_earlier_channel_is_an_assist(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 1, 300);
        $this->conversions('google', 1, 300);
        $c = $this->customer($store);
        // Before the window: the order that acquired the customer, through Google.
        $this->order('g-old', 200, $store, $this->campaign('google'), clickProvider: 'google', method: 'utm_campaign_id', customer: $c, daysAgo: 40);
        // In the window: the repeat order, through Meta.
        $this->order('m-new', 300, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', customer: $c);

        $m = $this->build()['model_comparison'];
        $rows = collect($m['channels'])->keyBy('channel');

        $this->assertTrue($m['available']);
        $this->assertSame(1, $rows['meta']['last_touch']['orders']);
        $this->assertSame(300.0, $rows['meta']['last_touch']['revenue']);
        $this->assertSame(0, $rows['meta']['first_touch']['orders']);
        $this->assertSame(0, $rows['google']['last_touch']['orders'], 'the old order is outside the window and is not counted again');
        $this->assertSame(1, $rows['google']['first_touch']['orders'], 'the window order goes to the channel that acquired the customer');
        $this->assertSame(300.0, $rows['google']['first_touch']['revenue'], 'the window order\'s revenue, not the old order\'s');
        $this->assertSame(1, $rows['google']['assisted']['orders']);
        $this->assertSame(0, $rows['meta']['assisted']['orders'], 'the last touch never assists itself');
        $this->assertSame(1, $m['paths']['multi_touch_orders']);
        $this->assertSame(['google', 'meta'], $m['paths']['rows'][0]['steps']);
        $this->assertSame(1, $m['coverage']['orders_with_customer']);
    }

    /** Every model hands each window order to exactly one channel; assists never add to a total. */
    public function test_each_model_places_every_order_once_and_last_touch_agrees_with_the_reconciliation(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 3, 300);
        $a = $this->customer($store);
        $b = $this->customer($store);
        $this->order('a0', 100, $store, null, clickProvider: 'google', method: 'click_id_platform_only', customer: $a, daysAgo: 20);
        $this->order('a1', 100, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', customer: $a);
        $this->order('b0', 100, $store, null, method: 'none', customer: $b, daysAgo: 30);
        $this->order('b1', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', customer: $b);
        $this->order('x1', 100, $store, null, method: 'none');
        $this->order('x2', 100, $store, null, method: 'none', cancelled: true);

        $built = $this->build();
        $m = $built['model_comparison'];

        $last = array_sum(array_map(static fn (array $c): int => $c['last_touch']['orders'], $m['channels']));
        $first = array_sum(array_map(static fn (array $c): int => $c['first_touch']['orders'], $m['channels']));
        $this->assertSame(3, $last, 'three live window orders; the cancelled one is no touch');
        $this->assertSame(3, $first);
        $this->assertSame(3, $m['coverage']['orders']);
        $this->assertSame(2, $m['coverage']['orders_with_customer'], 'the order with no customer is its own first touch and is counted as uncovered');

        $rows = collect($m['channels'])->keyBy('channel');
        $this->assertSame(1, $rows['google']['first_touch']['orders']);
        $this->assertSame(1, $rows['unattributed']['first_touch']['orders'] - 1, 'b was acquired unattributed; x1 has no customer and is unattributed on its own');
        $this->assertSame(1, $rows['unattributed']['assisted']['orders'], 'an unattributed earlier order is reported as such, never given to a platform');

        $reconciled = collect($built['reconciliation']['platforms'])->keyBy('provider');
        $this->assertSame($reconciled['meta']['reconciled_orders'], $rows['meta']['last_touch']['orders'], 'last touch is the reconciliation\'s own placement');
        $this->assertSame($built['reconciliation']['unattributed']['orders'], $rows['unattributed']['last_touch']['orders']);
    }

    /** The evidence behind each last touch is counted, and coupon orders are the creators' channel. */
    public function test_evidence_and_coupons_are_counted_on_the_channel_they_name(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 1, 100, '7d_click_1d_view');
        $this->order('k1', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only');
        $this->order('u1', 100, $store, $this->campaign('meta'), clickProvider: null, method: 'utm_campaign_name');
        $this->order('c1', 100, $store, null, method: 'influencer_coupon', coupon: 'NOURA10');

        $rows = collect($this->build()['model_comparison']['channels'])->keyBy('channel');

        $this->assertSame(1, $rows['meta']['evidence']['click_id']);
        $this->assertSame(1, $rows['meta']['evidence']['utm']);
        $this->assertSame(1, $rows['influencer']['last_touch']['orders']);
        $this->assertSame(1, $rows['influencer']['evidence']['coupon']);
        $this->assertSame('influencer', $rows['influencer']['kind']);
        $this->assertTrue($rows['meta']['claim_includes_view_through'], 'the platform\'s claim says it counts views; the store cannot, and says which is which');
        $this->assertSame(1.0, $rows['meta']['platform_claimed_orders']);
        $this->assertNull($rows['influencer']['platform_claimed_orders'], 'no platform claims a coupon order: null, not zero');
    }

    /** A cancelled earlier order acquired nobody. */
    public function test_a_cancelled_earlier_order_is_not_a_first_touch(): void
    {
        $store = $this->storeAccount();
        $c = $this->customer($store);
        $this->order('g-old', 100, $store, null, clickProvider: 'google', method: 'click_id_platform_only', customer: $c, daysAgo: 40, cancelled: true);
        $this->order('m-new', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', customer: $c);

        $rows = collect($this->build()['model_comparison']['channels'])->keyBy('channel');

        $this->assertSame(1, $rows['meta']['first_touch']['orders']);
        $this->assertArrayNotHasKey('google', $rows->all());
    }

    public function test_without_a_store_there_is_no_model_to_compare(): void
    {
        $this->conversions('meta', 4, 400);

        $m = $this->build()['model_comparison'];

        $this->assertFalse($m['available']);
        $this->assertSame('no_store_connected', $m['unavailable_reason']);
        $this->assertSame([], $m['channels']);
    }

    /** A client link that hides revenue hides it here too, and the block names no campaign. */
    public function test_a_client_link_hiding_revenue_withholds_the_model_revenue(): void
    {
        $store = $this->storeAccount();
        $c = $this->customer($store);
        $this->order('g', 100, $store, $this->campaign('google'), clickProvider: 'google', method: 'utm_campaign_id', customer: $c, daysAgo: 40);
        $this->order('m', 700, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', customer: $c);

        $share = new ReportShare;
        $share->forceFill(['hide_spend' => false, 'hide_revenue' => true]);
        $out = app(ShareService::class)->sanitizeAttribution($this->build(), $share);
        $m = $out['model_comparison'];

        foreach ($m['channels'] as $row) {
            foreach (['last_touch', 'first_touch', 'assisted'] as $model) {
                $this->assertNull($row[$model]['revenue'], $row['channel'].' '.$model);
            }
        }
        $this->assertNull($m['paths']['rows'][0]['revenue']);
        $this->assertSame(1, collect($m['channels'])->firstWhere('channel', 'meta')['last_touch']['orders'], 'orders are the subject and survive');
        $this->assertStringNotContainsString('Campaign', json_encode($m, JSON_THROW_ON_ERROR));
    }

    /** First touch reaches back to the first order; an assist and a path step must be inside the lookback. */
    public function test_assists_and_paths_read_the_lookback_while_first_touch_reads_the_first_order(): void
    {
        $store = $this->storeAccount();
        $c = $this->customer($store);
        $this->order('g-ancient', 100, $store, null, clickProvider: 'google', method: 'click_id_platform_only', customer: $c, daysAgo: 400);
        $this->order('s-recent', 100, $store, null, clickProvider: 'snapchat', method: 'click_id_platform_only', customer: $c, daysAgo: 30);
        $this->order('m-now', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', customer: $c);

        $m = $this->build()['model_comparison'];
        $rows = collect($m['channels'])->keyBy('channel');

        $this->assertSame(90, $m['lookback_days']);
        $this->assertSame(1, $rows['google']['first_touch']['orders'], 'the acquisition is the first order, however old');
        $this->assertSame(0, $rows['google']['assisted']['orders'], 'an order 400 days back is outside the lookback');
        $this->assertSame(1, $rows['snapchat']['assisted']['orders']);
        $this->assertSame(['snapchat', 'meta'], $m['paths']['rows'][0]['steps']);
    }

    private function customer(ExternalAccount $store): CommerceCustomer
    {
        return CommerceCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $store->getKey(), 'provider' => $store->provider,
            'external_id' => 'cust-'.uniqid(),
        ]);
    }

    private function build(): array
    {
        return app(AttributionTransparency::class)->build(
            $this->tenant->id, $this->project->id, Carbon::today()->subDays(7), Carbon::today(),
        );
    }

    private function storeAccount(string $provider = 'salla'): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider.'-'.uniqid(),
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'store', 'external_id' => 'shop-'.uniqid(),
            'name' => 'Store', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function order(
        string $externalId,
        float $total,
        ExternalAccount $account,
        ?ExternalCampaign $campaign,
        ?string $clickProvider = null,
        string $method = 'none',
        ?string $reference = null,
        bool $cancelled = false,
        float $refunded = 0.0,
        ?CommerceCustomer $customer = null,
        int $daysAgo = 1,
        ?string $coupon = null,
    ): CommerceOrder {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'provider' => $account->provider,
            'external_id' => $externalId, 'reference' => $reference, 'status' => 'completed',
            'placed_at' => Carbon::today()->subDays($daysAgo)->setTime(12, 0), 'currency' => 'SAR',
            'commerce_customer_id' => $customer?->getKey(), 'coupon_code' => $coupon,
            'total' => $total, 'refunded_total' => $refunded,
            'click_id' => $clickProvider === null ? null : 'click-'.uniqid(),
            'click_id_provider' => $clickProvider,
            'utm_source' => $clickProvider,
            'external_campaign_id' => $campaign?->getKey(),
            'unified_campaign_id' => $campaign?->unified_campaign_id,
            'attribution_method' => $method,
            'cancelled_at' => $cancelled ? Carbon::now() : null,
            'landing_url' => 'https://store.example/?x=1',
        ]);
    }

    /** @var array<string,ExternalCampaign> */
    private array $campaigns = [];

    private function campaign(string $provider): ExternalCampaign
    {
        return $this->campaigns[$provider] ??= ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount($provider)->getKey(), 'provider' => $provider,
            'external_id' => 'c-'.uniqid(), 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    /** @var array<string,ExternalAccount> */
    private array $adAccounts = [];

    private function adAccount(string $provider): ExternalAccount
    {
        if (isset($this->adAccounts[$provider])) {
            return $this->adAccounts[$provider];
        }
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: $provider.'-'.uniqid(),
        );

        return $this->adAccounts[$provider] = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'acct-'.uniqid(),
            'name' => 'Ad account', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function conversions(string $provider, float $count, float $revenue = 0.0, string $window = 'default'): void
    {
        foreach ([['conversions', $count], ['revenue', $revenue]] as [$key, $value]) {
            $this->metric($provider, $key, $value, $window);
        }
    }

    private function spend(string $provider, float $amount): void
    {
        $this->metric($provider, 'spend', $amount);
    }

    private function metric(string $provider, string $key, float $value, string $window = 'default'): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount($provider)->getKey(),
            'external_campaign_id' => $this->campaign($provider)->getKey(),
            'provider' => $provider, 'metric_key' => $key,
            'metric_date' => Carbon::today()->subDay()->toDateString(), 'value' => $value,
            'project_currency' => 'SAR', 'attribution_window' => $window, 'source_type' => 'api',
        ]);
    }

    private function measurement(string $key, float $value): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => app(TokenVault::class)->open(
                tenantId: $this->tenant->id, provider: 'ga4',
                tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
                connectionName: 'ga4-'.uniqid(),
            )->getKey(),
            'provider' => 'ga4', 'account_type' => 'property', 'external_id' => 'prop-'.uniqid(),
            'name' => 'Property', 'currency' => 'SAR', 'status' => 'active',
        ]);
        MeasurementDailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'property_id' => 'prop',
            'metric_date' => Carbon::today()->subDay()->toDateString(), 'metric_key' => $key,
            'value' => $value, 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh',
        ]);
    }
}
