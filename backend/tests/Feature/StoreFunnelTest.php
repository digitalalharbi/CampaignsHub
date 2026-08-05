<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FUNNEL-001 — «الفانل والمتجر», and the rule that makes it worth reading.
 *
 * Every stage states the system that produced it, and a stage nothing measures says so rather than
 * showing a zero. That distinction is the whole test suite: a zero in a funnel is a MEASUREMENT — it
 * says nobody added anything to a cart — and «لا يوجد مصدر يقيس هذه المرحلة» is a completely different
 * sentence that admits a completely different action.
 */
final class StoreFunnelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $store;

    private ExternalAccount $adAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'F', 'slug' => 'f-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@f.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        $this->store = $this->account('salla', 'store');
        $this->adAccount = $this->account('snapchat', 'ad_account');

        app(TenantContext::class)->forget();
    }

    // ── The claim the section exists to make ─────────────────────────────────────────────────

    /**
     * Product views and checkout starts are measured by NOTHING here, and the funnel says why.
     *
     * Neither Salla nor Zid exposes them, and no ad pixel reported them. Showing «0» would tell a
     * merchant nobody looked at their products.
     */
    public function test_a_stage_nothing_measures_is_unavailable_with_a_reason_rather_than_zero(): void
    {
        $response = $this->funnel();

        $stages = collect($response->json('data.stages'))->keyBy('key');

        foreach (['product_views', 'checkout_started'] as $key) {
            $this->assertNull($stages[$key]['value'], "{$key} must not be reported as a number");
            $this->assertSame('unavailable', $stages[$key]['state']);
            $this->assertSame('none', $stages[$key]['source']['kind']);
            $this->assertNotEmpty($stages[$key]['source']['ar']);
        }
    }

    /** Each measured stage names the system that produced it — the store, or the platforms' pixels. */
    public function test_every_measured_stage_names_the_system_that_produced_it(): void
    {
        $this->seedAds(impressions: 10000, clicks: 500, spend: 1000);
        $this->seedOrder('o1', total: 300, status: 'completed');

        $stages = collect($this->funnel()->json('data.stages'))->keyBy('key');

        $this->assertSame('ad_platforms', $stages['impressions']['source']['kind']);
        $this->assertSame(10000.0, (float) $stages['impressions']['value']);

        // The merchant's own ledger wins wherever it can answer at all.
        $this->assertSame('stores', $stages['orders']['source']['kind']);
        $this->assertSame(1.0, (float) $stages['orders']['value']);
        $this->assertSame('stores', $stages['revenue']['source']['kind']);
        $this->assertSame(300.0, (float) $stages['revenue']['value']);
    }

    /**
     * A store on a platform with no cart data makes the add-to-cart stage an UNDERCOUNT, and it says
     * so instead of reporting a near-perfect checkout rate.
     *
     * The Zid store files an order of its own, because that is what makes it THIS project's store
     * (UNIFIED-001): a shop belongs to the project its data was filed under, not to every project of
     * the tenant that owns it.
     */
    public function test_add_to_cart_is_partial_when_a_store_cannot_report_carts(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $zid = $this->account('zid', 'store');
        app(TenantContext::class)->forget();

        $this->seedOrder('o1', total: 100, status: 'completed');
        $this->seedOrder('z1', total: 80, status: 'completed', store: $zid, provider: 'zid');

        $stages = collect($this->funnel()->json('data.stages'))->keyBy('key');

        $this->assertSame('partial', $stages['add_to_cart']['state']);
        $this->assertNotEmpty($stages['add_to_cart']['note_ar']);
        $this->assertNotEmpty($this->funnel()->json('data.coverage.stores_without_cart_data'));
    }

    /**
     * A shop belonging to a DIFFERENT project of the same tenant is not this project's problem.
     *
     * The funnel used to list every store in the tenant, so an agency running two clients out of two
     * projects saw the other client's shop counted in `coverage.stores` and named in
     * `stores_without_cart_data` — a store name crossing a project boundary, and a cart-completeness
     * verdict decided by a shop the reader has nothing to do with.
     */
    public function test_a_store_belonging_to_another_project_does_not_appear_in_this_funnel(): void
    {
        $other = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'مشروع آخر',
            'status' => 'active',
        ]);

        $this->holdingTenant((string) $this->tenant->id);
        $elsewhere = $this->account('zid', 'store');
        app(TenantContext::class)->forget();

        $this->seedOrder('o1', total: 100, status: 'completed');
        $this->seedOrder('x1', total: 500, status: 'completed', store: $elsewhere, provider: 'zid', projectId: (string) $other->id);

        $coverage = $this->funnel()->json('data.coverage');
        $stages = collect($this->funnel()->json('data.stages'))->keyBy('key');

        $this->assertSame(1, $coverage['stores']);
        $this->assertSame([], $coverage['stores_without_cart_data']);
        // And the other project's revenue never reached this funnel either.
        $this->assertSame(100.0, (float) $stages['revenue']['value']);
        $this->assertSame('measured', $stages['add_to_cart']['state']);
    }

    /**
     * A shop that is connected and has never been swept is reported as such, not as «no store».
     *
     * The two call for different actions — go and connect one, versus wait or go and see why the sweep
     * has not run — and an operator told the first would try to reconnect a store already connected.
     */
    public function test_a_connected_store_awaiting_its_first_sync_is_named_rather_than_ignored(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $this->account('zid', 'store');
        app(TenantContext::class)->forget();

        $coverage = $this->funnel()->json('data.coverage');

        $this->assertSame(0, $coverage['stores']);
        $this->assertContains('zid', array_column($coverage['stores_pending_first_sync'], 'provider'));
    }

    public function test_add_to_cart_counts_orders_and_the_carts_that_never_became_one(): void
    {
        $this->seedOrder('o1', total: 100, status: 'completed');
        $this->seedOrder('o2', total: 150, status: 'completed');

        $this->holdingTenant((string) $this->tenant->id);
        CommerceAbandonedCart::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->store->getKey(), 'provider' => 'salla',
            'external_id' => 'cart-1', 'abandoned_at' => Carbon::now()->subDay(), 'total' => 90,
        ]);
        app(TenantContext::class)->forget();

        $stages = collect($this->funnel()->json('data.stages'))->keyBy('key');

        $this->assertSame(3.0, (float) $stages['add_to_cart']['value']);
        $this->assertSame('measured', $stages['add_to_cart']['state']);
    }

    // ── The money ────────────────────────────────────────────────────────────────────────────

    /**
     * A refunded order is not a return on anything, and a cancelled one is not revenue at all.
     */
    public function test_revenue_and_roas_count_what_the_merchant_actually_kept(): void
    {
        $this->seedAds(impressions: 1000, clicks: 100, spend: 200);
        $this->seedOrder('o1', total: 500, status: 'completed', refunded: 100);
        $this->seedOrder('o2', total: 300, status: 'completed', cancelled: true);

        $data = $this->funnel()->json('data');

        // 500 − 100 refunded; the cancelled order contributes nothing.
        $this->assertSame(400.0, (float) $data['totals']['revenue']);
        $this->assertSame(500.0, (float) $data['totals']['gross_revenue']);
        $this->assertSame(100.0, (float) $data['totals']['refunded']);
        $this->assertSame(1, $data['totals']['cancelled_orders']);
        $this->assertSame(2.0, (float) $data['derived']['roas']);
    }

    /**
     * CAC is not CPA.
     *
     * A returning customer's order is revenue, not an acquisition. Dividing spend by ALL orders and
     * calling it CAC flatters a store with loyal customers into thinking it acquires them cheaply.
     */
    public function test_cac_is_spend_over_new_customers_and_cpa_is_spend_over_orders(): void
    {
        $this->seedAds(impressions: 1000, clicks: 100, spend: 400);
        $this->seedOrder('o1', total: 100, status: 'completed');
        $this->seedOrder('o2', total: 100, status: 'completed');
        $this->seedOrder('o3', total: 100, status: 'completed');
        $this->seedOrder('o4', total: 100, status: 'completed');

        $this->holdingTenant((string) $this->tenant->id);
        CommerceCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->store->getKey(), 'provider' => 'salla',
            'external_id' => 'c-new', 'first_seen_at' => Carbon::now()->subDay(),
        ]);
        app(TenantContext::class)->forget();

        $derived = $this->funnel()->json('data.derived');

        $this->assertSame(100.0, (float) $derived['cpa']);  // 400 / 4 orders
        $this->assertSame(400.0, (float) $derived['cac']);  // 400 / 1 new customer
        $this->assertSame(100.0, (float) $derived['aov']);
    }

    // ── Attribution, and what it refuses to hide ─────────────────────────────────────────────

    /**
     * Orders that could not be traced are counted and REPORTED, never folded into the campaigns.
     *
     * An agency whose orders are mostly unattributed has a tracking problem worth more than any figure
     * on this page, and a funnel that quietly spread them across the campaigns would hide exactly that.
     */
    public function test_unattributed_orders_are_counted_separately_rather_than_spread_across_campaigns(): void
    {
        $campaign = $this->knownCampaign();

        $this->seedAds(impressions: 1000, clicks: 100, spend: 100);
        $this->seedOrder('o1', total: 200, status: 'completed', campaignId: $campaign->getKey(), method: 'utm_campaign_id');
        $this->seedOrder('o2', total: 800, status: 'completed', method: 'none');

        $data = $this->funnel()->json('data');

        $this->assertSame(2, $data['totals']['orders']);
        $this->assertSame(1, $data['totals']['attributed_orders']);
        $this->assertSame(1, $data['totals']['unattributed_orders']);
        $this->assertSame(200.0, (float) $data['totals']['attributed_revenue']);
        // ROAS over everything, and over what could actually be traced — both, side by side.
        $this->assertSame(10.0, (float) $data['derived']['roas']);
        $this->assertSame(2.0, (float) $data['derived']['attributed_roas']);
        $this->assertSame(1, $data['coverage']['orders_without_attribution']);

        $this->assertCount(1, $data['comparisons']['campaigns']);
        $this->assertSame(200.0, (float) $data['comparisons']['campaigns'][0]['revenue']);
    }

    /** A conversion rate that spans stages nobody measured says that it does. */
    public function test_a_step_that_spans_unmeasured_stages_is_marked_as_spanning_them(): void
    {
        $this->seedAds(impressions: 10000, clicks: 500, spend: 100);
        $this->seedOrder('o1', total: 100, status: 'completed');

        $steps = collect($this->funnel()->json('data.steps'));

        $clicksToCart = $steps->firstWhere('to', 'add_to_cart');
        $this->assertNotNull($clicksToCart);
        $this->assertSame('clicks', $clicksToCart['from']);
        // Visits and product views sit between them and neither was measured.
        $this->assertTrue($clicksToCart['spans_unmeasured_stages']);
        $this->assertSame(0.2, (float) $clicksToCart['conversion_rate']); // 1 of 500
        $this->assertSame(99.8, (float) $clicksToCart['drop_off']);
    }

    /** Best sellers come from the order LINES, not the catalogue price. */
    public function test_products_are_ranked_by_what_they_sold_in_the_window(): void
    {
        $order = $this->seedOrder('o1', total: 500, status: 'completed');

        $this->holdingTenant((string) $this->tenant->id);
        foreach ([['قميص', 2, 300.0], ['حذاء', 1, 200.0]] as [$name, $qty, $total]) {
            CommerceOrderItem::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'commerce_order_id' => $order->getKey(),
                'external_id' => $name, 'name' => $name, 'quantity' => $qty, 'total' => $total,
            ]);
        }
        app(TenantContext::class)->forget();

        $products = $this->funnel()->json('data.comparisons.products');

        $this->assertSame('قميص', $products[0]['name']);
        $this->assertSame(300.0, (float) $products[0]['revenue']);
        $this->assertSame(2.0, (float) $products[0]['quantity']);
    }

    // ── Isolation ────────────────────────────────────────────────────────────────────────────

    public function test_the_funnel_requires_permission_and_another_projects_orders_never_appear(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $other = Project::create([
            'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Other', 'status' => 'active',
        ]);
        CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $other->id,
            'external_account_id' => $this->store->getKey(), 'provider' => 'salla',
            'external_id' => 'elsewhere', 'status' => 'completed', 'total' => 9999,
            'placed_at' => Carbon::now()->subDay(),
        ]);
        app(TenantContext::class)->forget();

        $this->assertSame(0, $this->funnel()->json('data.totals.orders'));

        $stranger = User::create(['name' => 'S', 'email' => 's@f.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $this->tenant);

        $this->actingAs($stranger)
            ->getJson("/api/v1/projects/{$this->project->id}/commerce/funnel")
            ->assertStatus(403);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    private function funnel(): TestResponse
    {
        return $this->actingAs($this->operator)
            ->getJson("/api/v1/projects/{$this->project->id}/commerce/funnel")
            ->assertOk();
    }

    private function account(string $provider, string $type): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => $type,
            'external_id' => "{$provider}-{$type}", 'name' => ucfirst($provider),
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function knownCampaign(): ExternalCampaign
    {
        $this->holdingTenant((string) $this->tenant->id);

        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Ramadan', 'objective' => 'sales', 'status' => 'active',
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->adAccount->getKey(), 'unified_campaign_id' => $unified->id,
            'provider' => 'snapchat', 'external_id' => 'snap-1', 'name' => 'Ramadan', 'status' => 'active',
        ]);

        app(TenantContext::class)->forget();

        return $campaign;
    }

    private function seedAds(float $impressions, float $clicks, float $spend): void
    {
        // A metric belongs to a campaign — the DTO requires one, because a spend figure with no
        // campaign behind it is a number nobody can act on.
        $campaign = ExternalCampaign::withoutGlobalScopes()
            ->where('project_id', $this->project->id)->first() ?? $this->knownCampaign();

        $this->holdingTenant((string) $this->tenant->id);

        $metrics = [];

        foreach (['impressions' => $impressions, 'clicks' => $clicks, 'spend' => $spend] as $key => $value) {
            $metrics[] = new NormalizedMetric(
                tenantId: $this->tenant->id,
                projectId: $this->project->id,
                externalAccountId: $this->adAccount->getKey(),
                externalCampaignId: $campaign->getKey(),
                provider: 'snapchat',
                metricKey: $key,
                metricDate: Carbon::now()->subDay(),
                value: $value,
            );
        }

        app(UpsertDailyMetrics::class)->handle($metrics);
        app(TenantContext::class)->forget();
    }

    private function seedOrder(
        string $externalId,
        float $total,
        string $status,
        float $refunded = 0,
        bool $cancelled = false,
        ?string $campaignId = null,
        string $method = 'none',
        ?ExternalAccount $store = null,
        string $provider = 'salla',
        ?string $projectId = null,
    ): CommerceOrder {
        $this->holdingTenant((string) $this->tenant->id);

        $order = CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId ?? $this->project->id,
            'external_account_id' => ($store ?? $this->store)->getKey(),
            'provider' => $provider,
            'external_id' => $externalId,
            'status' => $status,
            'placed_at' => Carbon::now()->subDay(),
            'currency' => 'SAR',
            'total' => $total,
            'refunded_total' => $refunded ?: null,
            'cancelled_at' => $cancelled ? Carbon::now()->subHours(2) : null,
            'external_campaign_id' => $campaignId,
            'attribution_method' => $method,
            'attributed_at' => Carbon::now(),
        ]);

        app(TenantContext::class)->forget();

        return $order;
    }
}
