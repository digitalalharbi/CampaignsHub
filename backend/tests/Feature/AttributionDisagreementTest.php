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
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 (was ATTRIBUTION-MODELS-001's fixture set) — first touch, last touch, assists and conversion paths from the evidence
 * the store holds (Owner directive 7). A touch is an order with its own evidence; nothing is split
 * fractionally and nothing unattributed is distributed.
 */
final class AttributionDisagreementTest extends TestCase
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

    private function disagreement(int $days = 30): array
    {
        return app(AttributionTransparency::class)->build(
            $this->tenant->id, $this->project->id, Carbon::today()->subDays($days - 1), Carbon::today(),
        )['disagreement'];
    }

    private function claims(string $provider, float $orders, int $daysAgo): void
    {
        $this->metric($provider, 'conversions', $orders, 'default', $daysAgo);
    }

    /** A thirty-day window is read in seven-day periods, and the claim and the store sit in the period they happened. */
    public function test_a_month_is_split_into_weeks_and_each_figure_lands_in_its_own_week(): void
    {
        $store = $this->storeAccount();
        $this->claims('meta', 3, 1);
        $this->claims('meta', 2, 20);
        $this->order('a', 100, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', daysAgo: 1);
        $this->order('b', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', daysAgo: 20);
        $this->order('c', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', daysAgo: 20);

        $d = $this->disagreement();

        $this->assertTrue($d['available']);
        $this->assertSame('week', $d['grain']);
        $this->assertCount(5, $d['periods'], 'thirty days in sevens: four full weeks and two days');
        $meta = collect($d['platforms'])->firstWhere('provider', 'meta');
        $last = $meta['points'][count($meta['points']) - 1];
        $this->assertEquals(3, $last['claimed']);
        $this->assertSame(1, $last['confirmed']);
        $this->assertEquals(2, $last['gap']);
        $this->assertEquals(3.0, $last['ratio']);
        $this->assertEquals($last, $meta['widest_gap'], 'the period where the claim ran furthest ahead is named');
        $early = collect($meta['points'])->first(fn (array $p) => $p['confirmed'] === 2);
        $this->assertNotNull($early);
        $this->assertEquals(2, $early['claimed']);
    }

    /** The periods of one platform add up to its whole-window pair; cancelled orders are in neither. */
    public function test_the_periods_add_up_to_the_whole_window_pair(): void
    {
        $store = $this->storeAccount();
        $this->claims('meta', 4, 2);
        $this->claims('google', 1, 3);
        $this->order('a', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', daysAgo: 2);
        $this->order('b', 100, $store, null, clickProvider: 'google', method: 'click_id_platform_only', daysAgo: 3);
        $this->order('x', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', daysAgo: 2, cancelled: true);

        $all = app(AttributionTransparency::class)->build($this->tenant->id, $this->project->id, Carbon::today()->subDays(29), Carbon::today());
        $whole = collect($all['platform_reported']['platforms'] ?? [])->keyBy('provider');

        foreach ($all['disagreement']['platforms'] as $row) {
            $this->assertEquals($row['claimed'], array_sum(array_column($row['points'], 'claimed')));
            $this->assertSame($row['confirmed'], array_sum(array_column($row['points'], 'confirmed')));
            $this->assertEquals($whole[$row['provider']]['platform_reported_orders'], $row['claimed'], $row['provider'].' claim agrees with the whole-window figure');
            $this->assertSame($whole[$row['provider']]['store_confirmed_orders'], $row['confirmed'], $row['provider'].' confirmed agrees with the whole-window figure');
        }
        $this->assertSame(1, collect($all['disagreement']['platforms'])->firstWhere('provider', 'meta')['confirmed'], 'the cancelled order is not confirmed');
        $this->assertArrayNotHasKey('total', $all['disagreement'], 'no total across platforms');
    }

    /** A short window is read day by day, and a day the store confirmed nothing has a gap but no ratio. */
    public function test_a_short_window_is_daily_and_an_unconfirmed_day_has_no_ratio(): void
    {
        $store = $this->storeAccount();
        $this->claims('snapchat', 2, 1);
        // The store is connected and confirmed an order — on another day.
        $this->order('s', 100, $store, null, clickProvider: 'snapchat', method: 'click_id_platform_only', daysAgo: 3);

        $d = $this->disagreement(7);

        $this->assertSame('day', $d['grain']);
        $this->assertCount(7, $d['periods']);
        $point = collect(collect($d['platforms'])->firstWhere('provider', 'snapchat')['points'])->firstWhere('claimed', 2.0);
        $this->assertSame(0, $point['confirmed']);
        $this->assertNull($point['ratio']);
        $this->assertEquals(2, $point['gap']);
    }

    public function test_without_a_store_there_is_nothing_to_disagree_with(): void
    {
        $this->claims('meta', 3, 1);

        $d = $this->disagreement();

        $this->assertFalse($d['available']);
        $this->assertSame('no_store_connected', $d['unavailable_reason']);
        $this->assertSame([], $d['platforms']);
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

    private function metric(string $provider, string $key, float $value, string $window = 'default', int $daysAgo = 1): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount($provider)->getKey(),
            'external_campaign_id' => $this->campaign($provider)->getKey(),
            'provider' => $provider, 'metric_key' => $key,
            'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(), 'value' => $value,
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
