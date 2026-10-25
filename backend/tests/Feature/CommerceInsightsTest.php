<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * COMMERCE-INSIGHTS-001 — what the store itself says, with every figure checkable by hand.
 *
 * One fixed window (2026-10-01 → 2026-10-07) and a previous one (09-24 → 09-30), small enough to add up on paper. The properties
 * under test: totals reconcile (gross − refunds = net), cancelled orders are not sales, a withheld-money order is counted but not
 * summed, products/SKUs/categories/cities/customers/coupons are derived from real rows, growth is null when there was nothing before,
 * absence is stated (no cost data, no shipping destination, no carts on Zid), and another project's orders never appear.
 */
final class CommerceInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $analyst;

    private User $blind;

    private Project $project;

    private Project $other;

    private ExternalAccount $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->analyst = $this->member('a@ag.test', $role);
        $noAnalytics = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'N', 'slug' => 'n']);
        $noAnalytics->givePermissionTo(...Permission::where('key', '!=', 'analytics.view')->pluck('key')->all());
        $this->blind = $this->member('b@ag.test', $noAnalytics);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'active']);
        $this->store = $this->store('salla', $this->project);
    }

    private function member(string $email, Role $role): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant);
        $u->assignRole($role);

        return $u;
    }

    private function store(string $provider, Project $project): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(14)), connectionName: $provider.'-'.uniqid());
        $store = ExternalAccount::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider, 'account_type' => 'store', 'external_id' => $provider.uniqid(), 'name' => ucfirst($provider).' store', 'currency' => 'SAR', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id, 'external_account_id' => $store->id, 'provider' => $provider, 'purpose' => 'ecommerce', 'is_active' => true, 'sync_enabled' => true]);

        return $store;
    }

    private function customer(string $name, ?string $city, string $firstSeen): CommerceCustomer
    {
        return CommerceCustomer::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla', 'external_id' => $name, 'name' => $name, 'city' => $city, 'first_seen_at' => Carbon::parse($firstSeen)]);
    }

    /** @param  array<string,mixed>  $o */
    private function order(string $ref, string $at, float $total, array $o = [], ?CommerceCustomer $c = null, ?Project $project = null, ?ExternalAccount $store = null): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create($o + [
            'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id, 'external_account_id' => ($store ?? $this->store)->id, 'commerce_customer_id' => $c?->id,
            'provider' => 'salla', 'external_id' => $ref, 'reference' => $ref, 'status' => 'paid', 'payment_status' => 'paid', 'placed_at' => Carbon::parse($at), 'placed_on' => substr($at, 0, 10),
            'currency' => 'SAR', 'subtotal' => $total, 'total' => $total, 'discount_total' => 0, 'shipping_total' => 0, 'tax_total' => 0, 'refunded_total' => 0,
        ]);
    }

    private function line(CommerceOrder $o, string $name, ?string $sku, int $qty, float $total, ?CommerceProduct $p = null): void
    {
        CommerceOrderItem::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'commerce_order_id' => $o->id, 'commerce_product_id' => $p?->id, 'external_id' => uniqid(), 'product_external_id' => $sku ?? $name, 'sku' => $sku, 'name' => $name, 'quantity' => $qty, 'unit_price' => $total / $qty, 'total' => $total]);
    }

    private function insights(?string $qs = null, ?User $as = null, ?Project $project = null)
    {
        $qs ??= '?from=2026-10-01&to=2026-10-07';

        return $this->actingAs($as ?? $this->analyst)->getJson('/api/v1/projects/'.($project ?? $this->project)->id."/commerce/insights{$qs}");
    }

    private function fixture(): void
    {
        $shoes = CommerceProduct::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla', 'external_id' => 'p1', 'name' => 'Shoes', 'sku' => 'SH-1', 'category' => 'Footwear']);
        $bag = CommerceProduct::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla', 'external_id' => 'p2', 'name' => 'Bag', 'sku' => 'BG-1', 'category' => 'Bags']);
        $riyadh = $this->customer('Sara', 'Riyadh', '2026-10-02');      // new in the window
        $jeddah = $this->customer('Omar', 'Jeddah', '2026-06-01');      // returning
        $nowhere = $this->customer('Lina', null, '2026-05-01');         // returning, no city

        // 1: 200 gross, 20 discount, 15 shipping, 30 tax, coupon SAVE10 — Riyadh, new
        $o1 = $this->order('o1', '2026-10-02 10:00', 200, ['subtotal' => 175, 'discount_total' => 20, 'shipping_total' => 15, 'tax_total' => 30, 'coupon_code' => 'save10'], $riyadh);
        $this->line($o1, 'Shoes', 'SH-1', 2, 160, $shoes);
        $this->line($o1, 'Bag', 'BG-1', 1, 40, $bag);
        // 2: 100, refunded 40 — Jeddah, returning
        $o2 = $this->order('o2', '2026-10-03 12:00', 100, ['refunded_total' => 40], $jeddah);
        $this->line($o2, 'Bag', 'BG-1', 1, 100, $bag);
        // 3: 300, coupon SAVE10 again, no customer city
        $o3 = $this->order('o3', '2026-10-05 09:00', 300, ['coupon_code' => 'SAVE10', 'discount_total' => 30], $nowhere);
        $this->line($o3, 'Shoes', 'SH-1', 3, 300, $shoes);
        // 4: cancelled — not a sale
        $this->order('o4', '2026-10-06 09:00', 999, ['cancelled_at' => Carbon::parse('2026-10-06 10:00')], $jeddah);
        // 5: money withheld (rate unknown) — counted, never summed
        $this->order('o5', '2026-10-06 11:00', 0, ['total' => null, 'subtotal' => null, 'original_total' => 500, 'original_currency' => 'EUR'], $jeddah);
        // another project's order never appears
        $this->order('x1', '2026-10-04 09:00', 7777, [], null, $this->other, $this->store);
        // previous window: one order of 400 → growth is measurable
        $this->order('p1', '2026-09-26 09:00', 400, [], $jeddah);
        CommerceAbandonedCart::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla', 'external_id' => 'c1', 'abandoned_at' => Carbon::parse('2026-10-04 09:00'), 'currency' => 'SAR', 'total' => 80, 'items_count' => 2]);
    }

    public function test_the_totals_reconcile_by_hand_and_cancelled_and_withheld_orders_are_handled_as_stated(): void
    {
        $this->fixture();
        $t = $this->insights()->assertOk()->json('data.totals');

        $this->assertEquals(4, $t['orders'], 'o1,o2,o3,o5 are live; o4 is cancelled');
        $this->assertEquals(1, $t['cancelled_orders']);
        $this->assertEquals(3, $t['orders_priced'], 'the withheld order is not priced');
        $this->assertEquals(600.0, $t['gross_sales']);
        $this->assertEquals(50.0, $t['discounts']);
        $this->assertEquals(15.0, $t['shipping']);
        $this->assertEquals(30.0, $t['tax']);
        $this->assertEquals(40.0, $t['refunds']);
        $this->assertEquals(560.0, $t['net_sales'], 'gross − refunds');
        $this->assertEquals(186.67, $t['aov'], 'net / priced orders');
        $this->assertEquals(0.0667, $t['refund_rate']);
        $this->assertEquals(1, $this->insights()->json('data.provenance.orders_with_money_withheld'));
        $this->assertEquals($this->store->id, $this->insights()->json('data.provenance.stores.0.id'), 'the store the figures came from is named');
    }

    public function test_growth_is_against_the_previous_period_and_null_when_there_is_nothing_to_compare(): void
    {
        $this->fixture();
        $d = $this->insights()->assertOk()->json('data');
        $this->assertEquals(['from' => '2026-09-24', 'to' => '2026-09-30'], $d['window']['previous']);
        $this->assertEquals(400.0, $d['previous']['net_sales']);
        $this->assertEquals(40.0, $d['growth']['net_sales'], '(560 − 400) / 400');
        $this->assertEquals(300.0, $d['growth']['orders'], 'orders: 4 live now vs 1 before');

        $none = $this->insights('?from=2026-10-01&to=2026-10-07&compare=none')->json('data');
        $this->assertNull($none['previous']);
        $this->assertNull($none['growth']);

        $empty = $this->insights('?from=2026-01-01&to=2026-01-07')->json('data');
        $this->assertNull($empty['growth']['net_sales'], 'a percentage of a zero previous period is not a number');
    }

    public function test_the_trend_products_skus_and_categories_come_from_the_order_lines(): void
    {
        $this->fixture();
        $d = $this->insights()->json('data');

        $this->assertEquals([['2026-10-02', 1, 200.0], ['2026-10-03', 1, 60.0], ['2026-10-05', 1, 300.0]], array_map(fn ($r) => [$r['date'], $r['orders'], $r['net_sales']], array_slice($d['trend'], 0, 3)));

        $best = $d['products']['by_product']['best'];
        $this->assertEquals('SH-1', $best[0]['key']);
        $this->assertEquals(460.0, $best[0]['sales']);
        $this->assertEquals(5, $best[0]['quantity']);
        $this->assertEquals('BG-1', $best[1]['key']);
        $this->assertEquals(140.0, $best[1]['sales']);
        $this->assertEquals('BG-1', $d['products']['by_product']['worst'][0]['key']);
        $this->assertEquals(7, $d['products']['units']);

        $cats = array_column($d['categories']['rows'], 'sales', 'category');
        $this->assertEquals(['Footwear' => 460.0, 'Bags' => 140.0], $cats);
    }

    public function test_cities_say_what_they_are_based_on_and_count_the_unknown(): void
    {
        $this->fixture();
        $g = $this->insights()->json('data.geography');
        $this->assertEquals('customer_city', $g['basis']);
        $this->assertEquals('not_available', $g['shipping_destination']);
        $byCity = array_column($g['rows'], 'orders', 'city');
        $this->assertEquals(['Riyadh' => 1, 'Jeddah' => 2, 'unknown' => 1], $byCity, 'Jeddah: o2 and the withheld o5 (counted as an order, not as money); o3\'s customer has no city');
        $this->assertEquals(1, $g['unknown_orders']);
    }

    public function test_new_and_returning_customers_are_split_by_when_they_were_first_seen_and_retention_is_not_invented(): void
    {
        $this->fixture();
        $c = $this->insights()->json('data.customers');
        $this->assertEquals(1, $c['new']['customers']);
        $this->assertEquals(200.0, $c['new']['net_sales']);
        $this->assertEquals(2, $c['returning']['customers']);
        $this->assertEquals(360.0, $c['returning']['net_sales']);
        $this->assertNull($c['retention']);
        $this->assertEquals('needs_cohort_history', $c['retention_reason']);
    }

    public function test_coupons_are_grouped_case_insensitively_with_their_discount(): void
    {
        $this->fixture();
        $c = $this->insights()->json('data.coupons');
        $this->assertEquals(2, $c['orders_with_coupon']);
        $this->assertEquals('SAVE10', $c['rows'][0]['code']);
        $this->assertEquals(2, $c['rows'][0]['orders']);
        $this->assertEquals(50.0, $c['rows'][0]['discount']);
    }

    public function test_abandoned_carts_are_counted_for_salla_and_stated_unsupported_for_zid_alone(): void
    {
        $this->fixture();
        $a = $this->insights()->json('data.abandoned_carts');
        $this->assertTrue($a['supported']);
        $this->assertEquals(1, $a['count']);
        $this->assertEquals(80.0, $a['value']);

        $zidProject = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'Z', 'status' => 'active']);
        $this->store('zid', $zidProject);
        $z = $this->insights(null, null, $zidProject)->assertOk()->json('data.abandoned_carts');
        $this->assertFalse($z['supported']);
        $this->assertNull($z['count'], 'not a zero — the platform reports none');
    }

    public function test_absence_is_stated_not_zero_filled(): void
    {
        $d = $this->insights()->assertOk()->json('data');
        $this->assertEquals(['available' => false, 'reason' => 'no_cost_data'], $d['profitability']);
        $this->assertEquals(0, $d['totals']['orders']);
        $this->assertNull($d['totals']['aov']);
        $this->assertEquals(ReportingCurrency::DEFAULT, $d['provenance']['currency'], 'the project\'s reporting currency, converted at import');
        $this->assertFalse($d['provenance']['has_store'], 'a project no store has filed anything under lists no store');
        $this->assertEquals('store', $d['provenance']['source']);
        $this->assertEquals('Asia/Riyadh', $d['window']['timezone']);
    }

    public function test_another_projects_orders_never_appear_and_the_permission_is_analytics_view(): void
    {
        $this->fixture();
        $this->assertNotContains(7777.0, [$this->insights()->json('data.totals.gross_sales')]);
        $this->insights(null, $this->blind)->assertForbidden();
        $this->actingAs($this->analyst)->getJson('/api/v1/projects/'.$this->other->id.'/commerce/insights?from=2026-10-01&to=2026-10-07')->assertOk()->assertJsonPath('data.totals.gross_sales', 7777)->assertJsonPath('data.provenance.has_store', true);
    }

    public function test_another_tenants_project_is_not_reachable(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $ws = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'X', 'slug' => 'x-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $theirs = Project::create(['tenant_id' => $other->id, 'client_workspace_id' => $ws->id, 'name' => 'T', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->assertContains($this->insights(null, null, $theirs)->status(), [403, 404]);
    }
}
