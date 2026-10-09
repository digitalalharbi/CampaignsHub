<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
 * ATTRIBUTION-RECONCILIATION-001 — Owner directive 2026-10-09 §50: four truth layers, one order ledger,
 * deterministic evidence first, and no figure that was not measured.
 *
 *   * platform-reported — each platform's own claim, never summed across platforms;
 *   * measurement — GA4's transactions, its own layer, never blended into anybody's total;
 *   * commerce truth — the merchant's ledger, one row per sale;
 *   * reconciled — the ledger placed on platforms by the strongest evidence each order carries, so a
 *     sale two platforms both claimed is ONE order on ONE row, unattributed stays unattributed, and a
 *     conflicting signal is a conflict rather than a platform.
 *
 * ROAS is stated per basis and named as such; there is no «ROAS» without a basis.
 */
final class AttributionReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Rec', 'slug' => 'rec-'.uniqid(), 'status' => 'active']);
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

    // ── One ledger, one row per order ──────────────────────────────────────────────────────────

    /** Two platforms claiming the same sale: the claims stay apart, the ledger places the order once. */
    public function test_a_sale_two_platforms_claim_is_one_reconciled_order_on_one_platform(): void
    {
        $this->conversions('meta', 1, 400);
        $this->conversions('google', 1, 400);
        $this->spend('meta', 100);
        $this->spend('google', 100);
        $store = $this->storeAccount();
        // The order carries Meta's click id and a UTM naming the Meta campaign: deterministic, Meta.
        $this->order('o-1', 400, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id');

        $r = $this->build()['reconciliation'];

        $this->assertTrue($r['available']);
        $rows = collect($r['platforms'])->keyBy('provider');
        $this->assertSame(1, $rows['meta']['reconciled_orders']);
        $this->assertSame(0, $rows['google']['reconciled_orders']);
        $this->assertSame(1.0, $rows['meta']['platform_reported_orders']);
        $this->assertSame(1.0, $rows['google']['platform_reported_orders']);
        $this->assertSame(1, $rows['google']['overclaim_orders'], 'a claim with no ledger order behind it is an overclaim, per platform');
        $this->assertSame(0, $rows['meta']['overclaim_orders']);
        $this->assertSame(1, $r['ledger']['total'], 'the ledger holds the order once');
        $this->assertArrayNotHasKey('total_reconciled_orders_across_platforms', $r, 'no cross-platform total — the refusal holds here too');
    }

    /** Every live order lands in exactly one bucket: a platform row, unattributed, or conflict. */
    public function test_every_confirmed_order_is_counted_exactly_once(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 2, 100);
        $this->order('a', 100, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id');
        $this->order('b', 100, $store, null, clickProvider: 'meta', method: 'click_id_platform_only');
        $this->order('c', 100, $store, null, method: 'none');
        $this->order('d', 100, $store, null, clickProvider: 'google', method: 'conflict');
        $this->order('e', 100, $store, null, method: 'none', cancelled: true);

        $r = $this->build()['reconciliation'];

        $placed = array_sum(array_column($r['platforms'], 'reconciled_orders'));
        // ATTRIB-001: a conflicting signal is placed on the click id's platform and on no campaign,
        // so it is on a platform row AND counted as a conflict — a sub-count, not a fourth bucket.
        $this->assertSame(3, $placed);
        $this->assertSame(1, $r['unattributed']['orders']);
        $this->assertSame(1, $r['conflict']['orders']);
        $this->assertSame(4, $placed + $r['unattributed']['orders'], 'cancelled orders are not in the ledger; the rest sum to the ledger');
        $this->assertSame(4, $r['ledger']['total']);
    }

    /** Unattributed revenue is never spread across the platforms that happened to be running. */
    public function test_unattributed_stays_unattributed(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 5, 5000);
        $this->spend('meta', 1000);
        $this->order('u1', 900, $store, null, method: 'none');
        $this->order('u2', 900, $store, null, method: 'none');

        $r = $this->build()['reconciliation'];
        $meta = collect($r['platforms'])->firstWhere('provider', 'meta');

        $this->assertSame(0, $meta['reconciled_orders']);
        $this->assertSame(0.0, $meta['reconciled_revenue']);
        $this->assertSame(2, $r['unattributed']['orders']);
        $this->assertSame(1800.0, $r['unattributed']['revenue']);
        $this->assertNull($meta['roas']['reconciled']['value'], 'no revenue placed on Meta, so no reconciled ROAS — not zero, not platform-reported in disguise');
        $this->assertSame(1800.0, $r['business_roas']['revenue'], 'business ROAS counts the unattributed sales the platforms cannot claim');
        $this->assertSame(1.8, $r['business_roas']['value']);
    }

    /** ATTRIB-001: a conflicting signal is the click id's platform, no campaign — and it is counted as a conflict. */
    public function test_a_conflict_is_placed_by_the_click_id_and_named_as_a_conflict(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 1, 100);
        $this->conversions('google', 1, 100);
        $this->order('x', 250, $store, null, clickProvider: 'meta', method: 'conflict');

        $r = $this->build()['reconciliation'];
        $rows = collect($r['platforms'])->keyBy('provider');

        $this->assertSame(1, $rows['meta']['reconciled_orders']);
        $this->assertSame(0, $rows['google']['reconciled_orders']);
        $this->assertSame(1, $r['conflict']['orders']);
        $this->assertSame(250.0, $r['conflict']['revenue']);
        $this->assertNull($r['ledger']['rows'][0]['campaign']);
        // ATTR-EVIDENCE-INFLUENCER-COUPON-001 moved the creator's code to rank 4; a conflict is 6 now.
        $this->assertSame(6, $r['ledger']['rows'][0]['evidence_rank']);
    }

    // ── The layers ────────────────────────────────────────────────────────────────────────────

    /** With no store there is no ledger: the reconciled layer is unavailable, not a set of zeros. */
    public function test_without_a_store_the_reconciled_layer_is_unavailable_not_zero(): void
    {
        $this->conversions('meta', 3, 300);

        $r = $this->build()['reconciliation'];

        $this->assertFalse($r['available']);
        $this->assertSame('no_store_connected', $r['unavailable_reason']);
        $this->assertNull($r['ledger']);
        $meta = collect($r['platforms'])->firstWhere('provider', 'meta');
        $this->assertSame(3.0, $meta['platform_reported_orders']);
        $this->assertNull($meta['reconciled_orders']);
        $this->assertNull($meta['overclaim_orders']);
        $this->assertNull($r['business_roas']['value'], 'no ledger, no business ROAS');
    }

    /** GA4 is its own layer: reported where it was read, absent where it was not, and never blended. */
    public function test_measurement_is_its_own_layer_and_never_blended(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 2, 200);
        $this->order('m1', 150, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id');
        $this->measurement('transactions', 7);
        $this->measurement('revenue', 1234.5);

        $r = $this->build()['reconciliation'];

        $this->assertTrue($r['measurement']['available']);
        $this->assertSame(7.0, $r['measurement']['transactions']);
        $this->assertSame(1234.5, $r['measurement']['revenue']);
        $this->assertSame('measurement', $r['measurement']['basis']);
        $meta = collect($r['platforms'])->firstWhere('provider', 'meta');
        $this->assertSame(1, $meta['reconciled_orders'], 'the ledger, not GA4, decides the reconciled count');
        $this->assertSame(150.0, $meta['reconciled_revenue']);
    }

    public function test_measurement_absent_is_stated_as_absent(): void
    {
        $this->storeAccount();

        $r = $this->build()['reconciliation'];

        $this->assertFalse($r['measurement']['available']);
        $this->assertSame('no_measurement_rows', $r['measurement']['unavailable_reason']);
        $this->assertNull($r['measurement']['transactions']);
    }

    // ── ROAS names its basis ──────────────────────────────────────────────────────────────────

    public function test_roas_is_stated_per_basis_platform_reconciled_and_business_and_never_without_one(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 4, 800);
        $this->spend('meta', 200);
        $this->order('r1', 300, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id');

        $meta = collect($this->build()['reconciliation']['platforms'])->firstWhere('provider', 'meta');

        $this->assertSame(200.0, $meta['spend']);
        $this->assertSame('platform_reported', $meta['roas']['platform_reported']['basis']);
        $this->assertSame(4.0, $meta['roas']['platform_reported']['value']);
        $this->assertSame('reconciled', $meta['roas']['reconciled']['basis']);
        $this->assertSame(1.5, $meta['roas']['reconciled']['value']);
        $this->assertArrayNotHasKey('value', $meta['roas'], 'no bare ROAS');
        $r = $this->build()['reconciliation'];
        $this->assertSame('store_confirmed', $r['business_roas']['basis']);
        $this->assertSame(1.5, $r['business_roas']['value'], 'one platform, one order: business and reconciled agree here and diverge only when sales go unattributed');
    }

    // ── The ledger ────────────────────────────────────────────────────────────────────────────

    /** Deterministic evidence first; the merchant's reference, never an internal id or a path. */
    public function test_the_ledger_is_ordered_by_evidence_and_carries_the_merchants_reference(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 1, 100);
        $this->order('weak', 50, $store, null, method: 'none', reference: 'ORD-9');
        $this->order('strong', 60, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', reference: 'ORD-1');
        $this->order('mid', 70, $store, null, clickProvider: 'meta', method: 'click_id_platform_only', reference: 'ORD-5');

        $ledger = $this->build()['reconciliation']['ledger'];

        $this->assertSame(['ORD-1', 'ORD-5', 'ORD-9'], array_column($ledger['rows'], 'reference'));
        $this->assertSame([1, 3, 7], array_column($ledger['rows'], 'evidence_rank'));
        $this->assertSame('meta', $ledger['rows'][0]['platform']);
        $this->assertNull($ledger['rows'][2]['platform']);
        foreach ($ledger['rows'] as $row) {
            $this->assertArrayNotHasKey('id', $row);
            $this->assertArrayNotHasKey('external_account_id', $row);
            $this->assertArrayNotHasKey('landing_url', $row);
        }
    }

    public function test_the_ledger_is_capped_and_says_so(): void
    {
        $store = $this->storeAccount();
        for ($i = 0; $i < 205; $i++) {
            $this->order('cap-'.$i, 10, $store, null, method: 'none', reference: 'R'.$i);
        }

        $ledger = $this->build()['reconciliation']['ledger'];

        $this->assertSame(205, $ledger['total']);
        $this->assertCount(200, $ledger['rows']);
        $this->assertTrue($ledger['truncated']);
    }

    public function test_refunds_reduce_reconciled_revenue_and_are_shown(): void
    {
        $store = $this->storeAccount();
        $this->conversions('meta', 1, 100);
        $this->order('rf', 500, $store, $this->campaign('meta'), clickProvider: 'meta', method: 'utm_campaign_id', refunded: 120);

        $r = $this->build()['reconciliation'];
        $meta = collect($r['platforms'])->firstWhere('provider', 'meta');

        $this->assertSame(380.0, $meta['reconciled_revenue']);
        $this->assertSame(120.0, $r['ledger']['rows'][0]['refunded']);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** ATTR-EVIDENCE-INFLUENCER-COUPON-001 — a creator's code is its own layer: not a platform, not unattributed. */
    public function test_an_order_placed_on_a_creator_code_is_its_own_layer_and_still_one_order(): void
    {
        $store = $this->storeAccount();
        $this->spend('meta', 1000.0);
        $this->conversions('meta', 2.0, 500.0);
        $this->order('o-1', 300.0, $store, $this->campaign('meta'), null, 'utm_campaign_id', 'REF-1');
        $coupon = $this->order('o-2', 200.0, $store, null, null, 'influencer_coupon', 'REF-2');
        $coupon->forceFill(['coupon_code' => 'SARA20'])->save();
        $this->order('o-3', 100.0, $store, null, null, 'none', 'REF-3');

        $r = $this->build()['reconciliation'];

        $this->assertSame(1, $r['influencer']['orders']);
        $this->assertSame(200.0, $r['influencer']['revenue']);
        $this->assertSame([['code' => 'SARA20', 'orders' => 1, 'revenue' => 200.0]], $r['influencer']['codes']);
        $this->assertSame(1, $r['unattributed']['orders'], 'the coupon order is not unattributed');
        $meta = collect($r['platforms'])->firstWhere('provider', 'meta');
        $this->assertSame(1, $meta['reconciled_orders'] ?? $meta['orders'] ?? null, 'the coupon order is not placed on a platform');
        $row = collect($r['ledger']['rows'])->firstWhere('reference', 'REF-2');
        $this->assertSame('SARA20', $row['coupon']);
        $this->assertNull($row['platform']);
        $this->assertSame(4, $row['evidence_rank'], 'below a click id (3), above a bare utm_source (5)');
        $this->assertSame(3, $r['ledger']['total'], 'one order, one row — the code adds evidence, never a second order');
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
    ): CommerceOrder {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'provider' => $account->provider,
            'external_id' => $externalId, 'reference' => $reference, 'status' => 'completed',
            'placed_at' => Carbon::today()->subDay(), 'currency' => 'SAR',
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

    private function conversions(string $provider, float $count, float $revenue = 0.0): void
    {
        foreach ([['conversions', $count], ['revenue', $revenue]] as [$key, $value]) {
            $this->metric($provider, $key, $value);
        }
    }

    private function spend(string $provider, float $amount): void
    {
        $this->metric($provider, 'spend', $amount);
    }

    private function metric(string $provider, string $key, float $value): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount($provider)->getKey(),
            'external_campaign_id' => $this->campaign($provider)->getKey(),
            'provider' => $provider, 'metric_key' => $key,
            'metric_date' => Carbon::today()->subDay()->toDateString(), 'value' => $value,
            'project_currency' => 'SAR', 'attribution_window' => 'default', 'source_type' => 'api',
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
