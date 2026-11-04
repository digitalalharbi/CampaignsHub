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
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * STORE-ANALYTICS-DEPTH-001, TRAFFIC-DRILLDOWN-001, GA4-PLATFORM-COMPARE-001 — a confirmed order and its
 * source, the store's own reading, and the three sources side by side.
 *
 * A store order ties to a source only by evidence in the row. Nothing here reaches a real store or a real
 * GA4 property: the rows are written directly, so these tests prove reading, scope and labels only.
 */
final class StoreAttributionDepthTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private Project $sibling;

    private ExternalAccount $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
        $a = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $a->id, 'name' => 'P', 'status' => 'active']);
        $this->sibling = Project::create(['client_workspace_id' => $b->id, 'name' => 'Q', 'status' => 'active']);
        $this->store = $this->storeFor($this->project, 'shop-1');
        app(TenantContext::class)->forget();
    }

    // ── A confirmed order and its source ───────────────────────────────────────────────────────

    public function test_each_order_names_the_evidence_that_ties_it_and_otherwise_reads_unattributed(): void
    {
        $this->order('u1', 100, ['attribution_method' => 'utm_campaign_name', 'utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'Ramadan']);
        $this->order('c1', 100, ['attribution_method' => 'click_id_platform_only', 'click_id' => 'x', 'click_id_provider' => 'tiktok']);
        $this->order('n1', 100, ['attribution_method' => 'none', 'utm_source' => 'newsletter', 'utm_medium' => 'email']);
        $this->order('k1', 100, ['attribution_method' => 'none', 'coupon_code' => 'SAVE10']);
        $this->order('r1', 100, ['attribution_method' => 'none', 'referrer_url' => 'https://blog.example.org/post?x=1', 'landing_url' => 'https://shop.test/p']);
        $this->order('i1', 100, ['attribution_method' => 'none', 'referrer_url' => 'https://shop.test/cart', 'landing_url' => 'https://shop.test/p']);
        $this->order('x1', 100, ['attribution_method' => 'conflict', 'click_id' => 'y', 'click_id_provider' => 'meta', 'utm_campaign' => 'GoogleOne']);
        $this->order('z1', 100, ['attribution_method' => 'none']);
        $this->order('z2', 100, ['attribution_method' => null]);

        $rows = collect($this->data('commerce/orders?from=2026-10-01&to=2026-10-31&per_page=50')['rows'])->keyBy('reference');

        $this->assertSame('utm_campaign', $rows['u1']['method']);
        $this->assertSame('click_id', $rows['c1']['method']);
        $this->assertSame('tiktok', $rows['c1']['source']);
        $this->assertSame('utm_source', $rows['n1']['method'], 'a UTM word that names no ad platform is still evidence of a source');
        $this->assertSame('newsletter', $rows['n1']['source']);
        $this->assertSame('coupon', $rows['k1']['method']);
        $this->assertSame('referral', $rows['r1']['method']);
        $this->assertSame('blog.example.org', $rows['r1']['source']);
        $this->assertSame('unattributed', $rows['i1']['method'], 'the shop referring to itself proves nothing');
        $this->assertSame('conflict', $rows['x1']['method']);
        $this->assertNull($rows['x1']['source'], 'two signals that disagree are not resolved by preference');
        foreach (['z1', 'z2'] as $ref) {
            $this->assertSame('unattributed', $rows[$ref]['method']);
            $this->assertNull($rows[$ref]['source'], 'never guessed');
            $this->assertSame('غير منسوب', $rows[$ref]['method_label']['ar']);
        }
    }

    public function test_an_order_row_carries_no_customer_identity_and_keeps_its_original_currency(): void
    {
        $customer = CommerceCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id,
            'provider' => 'salla', 'external_id' => 'cu1', 'name' => 'Fatimah Secret', 'email' => 'fatimah@secret.test', 'phone' => '+966500000001',
        ]);
        $this->order('p1', 375, [
            'commerce_customer_id' => $customer->id, 'currency' => 'USD', 'original_currency' => 'SAR', 'original_total' => 375, 'total' => 100,
            'landing_url' => 'https://shop.test/p?email=fatimah@secret.test&token=abc',
        ]);

        $body = json_encode($this->data('commerce/orders?from=2026-10-01&to=2026-10-31'), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Fatimah', (string) $body);
        $this->assertStringNotContainsString('fatimah', (string) $body, 'not even inside a landing query string');
        $this->assertStringNotContainsString('+966500000001', (string) $body);

        $row = $this->data('commerce/orders?from=2026-10-01&to=2026-10-31')['rows'][0];
        $this->assertSame('SAR', $row['original_currency']);
        $this->assertEquals(375.0, $row['original_total']);
        $this->assertEquals(100.0, $row['net']);
        $this->assertSame('/p', $row['landing_path']);
    }

    public function test_a_withheld_conversion_is_not_a_zero_and_cancelled_orders_are_not_sources(): void
    {
        $this->order('e1', 0, ['total' => null, 'original_total' => 90, 'original_currency' => 'EUR', 'utm_source' => 'newsletter', 'attribution_method' => 'none']);
        $this->order('g1', 100, ['utm_source' => 'newsletter', 'attribution_method' => 'none', 'cancelled_at' => '2026-10-06 10:00:00', 'status' => 'cancelled']);

        $orders = collect($this->data('commerce/orders?from=2026-10-01&to=2026-10-31')['rows'])->keyBy('reference');
        $this->assertNull($orders['e1']['net'], 'withheld, not zero');
        $this->assertTrue($orders['e1']['money_withheld']);
        $this->assertSame('EUR', $orders['e1']['original_currency']);

        $out = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31');
        $row = collect($out['rows'])->first(fn ($r) => $r['dimension_1'] === 'newsletter');
        $this->assertSame(1, $row['orders'], 'a cancelled order is not counted under its source');
        $this->assertSame(1, $out['totals']['cancelled_excluded']);
        $this->assertSame(1, $out['totals']['money_withheld_orders']);
        $this->assertSame(['EUR'], $out['totals']['money_withheld_currencies']);
    }

    public function test_sources_page_sort_filter_on_the_server_and_state_the_method_per_row(): void
    {
        foreach (range(1, 12) as $i) {
            $this->order("o{$i}", 10 * $i, ['utm_source' => "src{$i}", 'utm_medium' => 'm', 'attribution_method' => 'none', 'status' => 'completed']);
        }
        $this->order('un', 5, ['attribution_method' => 'none']);

        $page = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31&per_page=10&page=2&sort=net_revenue&dir=desc');
        $this->assertSame(13, $page['total']);
        $this->assertCount(3, $page['rows']);
        $this->assertSame(2, $page['page']);
        $first = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31&per_page=10&sort=net_revenue&dir=desc')['rows'][0];
        $this->assertSame('src12', $first['dimension_1']);
        $this->assertSame(['utm_source' => 1], $first['methods']);
        $this->assertSame('USD', $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31')['currency']);

        $found = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31&q=src12');
        $this->assertSame(1, $found['total']);

        $none = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31&method=unattributed');
        $this->assertSame(1, $none['total']);
        $this->assertSame('unattributed', $none['rows'][0]['kind']);

        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('commerce/orders/sources?sort=password'))->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('commerce/orders/sources?group=customer_email'))->assertStatus(422);
    }

    public function test_sources_sit_beside_ga4_sessions_and_conversion_never_adds_the_two(): void
    {
        $this->order('a', 100, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'attribution_method' => 'none', 'status' => 'completed']);
        $property = $this->property($this->project);
        $this->dimRow($property, $this->project, 'source_medium', 'newsletter', 'email', ['sessions' => 50, 'add_to_carts' => 4, 'purchases' => 3, 'revenue' => 900]);
        $this->dimRow($property, $this->project, 'source_medium', 'google', 'organic', ['sessions' => 70, 'purchases' => 1, 'revenue' => 10]);

        $rows = collect($this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31')['rows'])->keyBy('dimension_1');
        $this->assertSame(1, $rows['newsletter']['orders']);
        $this->assertEquals(100.0, $rows['newsletter']['net_revenue'], 'the store figure, not store + GA4');
        $this->assertEquals(900.0, $rows['newsletter']['ga4_revenue']);
        $this->assertEquals(50.0, $rows['newsletter']['ga4_sessions']);
        $this->assertEquals(0.02, $rows['newsletter']['confirmed_per_session']);
        $this->assertSame(0, $rows['google']['orders'], 'the ledger was read and holds none');
    }

    public function test_with_no_store_the_answer_says_so_rather_than_zeroes(): void
    {
        $out = $this->data('commerce/orders/sources?from=2026-10-01&to=2026-10-31', $this->sibling);
        $this->assertSame('no_store', $out['state']);
        $this->assertSame([], $out['rows']);
        $this->assertNull($out['totals']['orders']);
        $depth = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31', $this->sibling);
        $this->assertSame('no_store', $depth['state']);
        $this->assertArrayNotHasKey('sales', $depth);
    }

    public function test_exports_carry_every_row_in_scope_whatever_the_page_and_quote_formula_cells(): void
    {
        foreach (range(1, 12) as $i) {
            $this->order("o{$i}", 10, ['utm_source' => $i === 1 ? '=HYPERLINK("http://x")' : "s{$i}", 'attribution_method' => 'none']);
        }
        $sources = $this->download('commerce/orders/sources/export?from=2026-10-01&to=2026-10-31&format=csv');
        $this->assertSame(13, substr_count($sources, "\n"), 'header + 12 rows, not 10');
        $this->assertStringContainsString("'=hyperlink", $sources);

        $orders = $this->download('commerce/orders/export?from=2026-10-01&to=2026-10-31&format=csv');
        $this->assertSame(13, substr_count($orders, "\n"));
        $this->assertStringContainsString('Method', $orders);

        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('commerce/orders/export?format=exe'))->assertStatus(422);
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('commerce/orders/sources/export'))->assertStatus(422);
    }

    // ── Isolation ──────────────────────────────────────────────────────────────────────────────

    public function test_no_endpoint_shows_another_project_or_a_deselected_store(): void
    {
        $this->order('mine', 100, ['utm_source' => 'mine-source', 'attribution_method' => 'none']);
        $theirs = $this->storeFor($this->sibling, 'shop-2');
        $this->order('theirs', 700, ['utm_source' => 'their-source', 'attribution_method' => 'none'], $theirs, $this->sibling);
        $unbound = $this->storeFor($this->project, 'shop-3');
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $unbound->id)->update(['is_active' => false]);
        $this->order('hidden', 900, ['utm_source' => 'hidden-source', 'attribution_method' => 'none'], $unbound);

        foreach (['commerce/orders', 'commerce/orders/sources', 'commerce/depth', 'measurement/compare'] as $path) {
            $body = (string) json_encode($this->data("$path?from=2026-10-01&to=2026-10-31"));
            $this->assertStringNotContainsString('their-source', $body, $path);
            $this->assertStringNotContainsString('hidden-source', $body, $path);
            $this->assertStringNotContainsString('hidden', $body, $path);
        }
        $this->assertEquals(100.0, $this->data('commerce/depth?from=2026-10-01&to=2026-10-31')['sales']['net']['current']);
    }

    public function test_a_member_of_another_tenant_is_refused_every_endpoint(): void
    {
        $this->order('mine', 100);
        $other = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $other->id);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'O', 'slug' => 'o2']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'X', 'email' => 'x-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);
        app(TenantContext::class)->forget();

        foreach (['commerce/orders', 'commerce/orders/export', 'commerce/orders/sources', 'commerce/orders/sources/export', 'commerce/depth', 'measurement/compare'] as $path) {
            $code = $this->actingAs($stranger, 'sanctum')->getJson($this->url($path))->getStatusCode();
            $this->assertContains($code, [403, 404], "$path answered $code");
        }
    }

    // ── The store's own reading ────────────────────────────────────────────────────────────────

    public function test_depth_states_gross_net_discount_shipping_tax_states_and_growth(): void
    {
        $this->order('a', 200, ['status' => 'completed', 'subtotal' => 180, 'discount_total' => 20, 'shipping_total' => 30, 'tax_total' => 10, 'refunded_total' => 50]);
        $this->order('b', 100, ['status' => 'completed']);
        $this->order('c', 300, ['status' => 'cancelled', 'cancelled_at' => '2026-10-06 10:00:00']);
        $this->order('d', 50, ['status' => 'pending']);
        $this->order('prev', 100, ['status' => 'completed', 'placed_at' => '2026-09-20 10:00:00']);

        $d = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31');
        $this->assertSame('ready', $d['state']);
        $this->assertEquals(350.0, $d['sales']['gross']['current']);
        $this->assertEquals(300.0, $d['sales']['net']['current'], 'gross less refunds; a cancelled order was never revenue');
        $this->assertEquals(20.0, $d['sales']['discounts']['current']);
        $this->assertEquals(30.0, $d['sales']['shipping']['current']);
        $this->assertEquals(10.0, $d['sales']['tax']['current']);
        $this->assertEquals(50.0, $d['sales']['refunded']['current']);
        $this->assertEquals(100.0, $d['sales']['net']['previous']);
        $this->assertEquals(2.0, $d['sales']['net']['change_pct']);
        $this->assertSame(4, $d['orders']['total']);
        $this->assertSame(3, $d['orders']['live']);
        $this->assertSame(2, $d['orders']['confirmed']);
        $this->assertSame(1, $d['orders']['pending']);
        $this->assertSame(1, $d['orders']['cancelled']);
        $this->assertSame(1, $d['orders']['refunded']);
        $this->assertEquals(100.0, $d['averages']['aov'], '300 net / 3 live');
        $this->assertSame('store_confirmed', $d['basis']['source']);
    }

    public function test_a_figure_no_order_states_is_absent_not_zero(): void
    {
        $this->order('a', 100, ['status' => 'completed']);
        $d = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31');
        $this->assertNull($d['sales']['tax']['current']);
        $this->assertNull($d['sales']['discounts']['current']);
        $this->assertNull($d['averages']['items_per_order'], 'no order lines read');
        $this->assertNull($d['conversion']['value']);
        $this->assertNull($d['conversion']['visits_source']);
        $this->assertNull($d['sales']['net']['previous']);
        $this->assertNull($d['sales']['net']['change_pct']);
        $this->assertNull($d['abandoned']['by_product']);
        $this->assertNotSame('', $d['abandoned']['by_product_reason']['ar']);
        $this->assertSame([], $d['products']['categories']);
    }

    public function test_products_rank_by_orders_and_revenue_with_weak_categories_and_unsold(): void
    {
        $tee = $this->product('p-tee', 'Tee', 'Clothes');
        $mug = $this->product('p-mug', 'Mug', 'Home');
        $this->product('p-hat', 'Hat', 'Clothes');
        foreach (['a', 'b', 'c'] as $ref) {
            $this->item($this->order($ref, 10, ['status' => 'completed']), $mug, 1, 10);
        }
        $this->item($this->order('d', 500, ['status' => 'completed']), $tee, 2, 500);

        $d = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31');
        $this->assertSame('Tee', $d['products']['best_by_revenue'][0]['name']);
        $this->assertSame('Mug', $d['products']['best_by_orders'][0]['name']);
        $this->assertSame(3, $d['products']['best_by_orders'][0]['orders']);
        $this->assertSame('Mug', $d['products']['weak'][0]['name'], 'lowest revenue of what sold');
        $this->assertSame(1, $d['products']['unsold_active']['count']);
        $this->assertSame(['Hat'], $d['products']['unsold_active']['sample']);
        $this->assertSame('Clothes', $d['products']['categories'][0]['category']);
        $this->assertEquals(500.0, $d['products']['categories'][0]['revenue']);
        $this->assertEquals(1.25, $d['averages']['items_per_order'], '5 units / 4 orders');
    }

    public function test_new_returning_repeat_and_lifetime_value_only_on_a_reliable_ledger(): void
    {
        $old = $this->customer('old', '2026-01-01 10:00:00');
        $new = $this->customer('new', null);
        $this->order('h', 100, ['status' => 'completed', 'placed_at' => '2026-02-01 10:00:00', 'commerce_customer_id' => $old->id]);
        $this->order('a', 100, ['status' => 'completed', 'commerce_customer_id' => $old->id]);
        $this->order('b', 100, ['status' => 'completed', 'commerce_customer_id' => $new->id]);
        $this->order('c', 100, ['status' => 'completed']);

        $c = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31')['customers'];
        $this->assertSame(1, $c['returning']);
        $this->assertSame(1, $c['new']);
        $this->assertSame(1, $c['without_customer']);
        $this->assertEquals(0.5, $c['repeat_rate']);
        $this->assertNull($c['lifetime_value'], 'two customers are not a basis');
        $this->assertSame('too_few_customers', $c['lifetime_value_reason']);
    }

    public function test_conversion_names_the_system_that_counted_the_visits(): void
    {
        $this->order('a', 100, ['status' => 'completed']);
        $this->metric('landing_page_views', 200);
        $this->assertSame('ad_platforms', $this->data('commerce/depth?from=2026-10-01&to=2026-10-31')['conversion']['visits_source']);

        $property = $this->property($this->project);
        DB::table('measurement_daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $property->id,
            'property_id' => '111', 'metric_date' => '2026-10-05', 'metric_key' => 'sessions', 'value' => 400, 'currency' => null, 'timezone' => 'Asia/Riyadh',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $conv = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31')['conversion'];
        $this->assertSame('ga4', $conv['visits_source']);
        $this->assertEquals(400.0, $conv['visits']);
        $this->assertEquals(0.0025, $conv['value']);
    }

    public function test_demo_rows_are_labelled_on_the_operator_reading(): void
    {
        $this->order('real', 100, ['status' => 'completed']);
        $this->order('demo', 900, ['status' => 'completed', 'is_demo' => true]);
        $d = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31');
        $this->assertSame('mixed', $d['basis']['kind']);
        $this->assertSame(1, $d['basis']['demo_orders']);
    }

    public function test_abandoned_carts_state_their_count_and_value(): void
    {
        $this->order('a', 100, ['status' => 'completed']);
        CommerceAbandonedCart::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id, 'provider' => 'salla',
            'external_id' => 'cart-1', 'abandoned_at' => '2026-10-07 10:00:00', 'currency' => 'USD', 'total' => 80, 'customer_email' => 'buyer@secret.test',
        ]);
        $d = $this->data('commerce/depth?from=2026-10-01&to=2026-10-31');
        $this->assertSame(1, $d['abandoned']['carts']);
        $this->assertEquals(80.0, $d['abandoned']['value']);
        $this->assertStringNotContainsString('buyer@secret.test', (string) json_encode($d));
    }

    public function test_the_funnel_fills_a_step_only_the_site_measured_and_names_ga4_as_its_source(): void
    {
        $this->order('a', 100, ['status' => 'completed']);
        $before = collect($this->data('commerce/funnel?from=2026-10-01&to=2026-10-31')['stages'])->keyBy('key');
        $this->assertNull($before['visits']['value']);
        $this->assertNull($before['product_views']['value']);

        $property = $this->property($this->project);
        foreach (['sessions' => 400, 'product_views' => 90] as $key => $value) {
            DB::table('measurement_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $property->id,
                'property_id' => '111', 'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'currency' => null, 'timezone' => 'Asia/Riyadh',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->dimRow($property, $this->project, 'source_medium', 'google', 'cpc', ['sessions' => 400, 'checkouts' => 12]);

        $after = collect($this->data('commerce/funnel?from=2026-10-01&to=2026-10-31')['stages'])->keyBy('key');
        $this->assertEquals(400.0, $after['visits']['value']);
        $this->assertSame('ga4', $after['visits']['source']['kind']);
        $this->assertNotEmpty($after['visits']['note_ar'], 'it says it covers all traffic, not paid only');
        $this->assertEquals(90.0, $after['product_views']['value']);
        $this->assertEquals(12.0, $after['checkout_started']['value']);
        $this->assertSame('stores', $after['orders']['source']['kind'], 'the store stays the authority on orders');
    }

    // ── Three sources, never added ─────────────────────────────────────────────────────────────

    public function test_the_three_sources_sit_side_by_side_with_their_labels_and_are_never_summed(): void
    {
        $this->metric('conversions', 10, 'meta');
        $this->metric('revenue', 1000, 'meta');
        $this->order('a', 100, ['attribution_method' => 'click_id_platform_only', 'click_id' => 'k', 'click_id_provider' => 'meta', 'status' => 'completed']);
        $this->order('un', 40, ['attribution_method' => 'none', 'status' => 'completed']);
        $property = $this->property($this->project);
        $this->dimRow($property, $this->project, 'source_medium', 'facebook', 'cpc', ['sessions' => 30, 'purchases' => 4, 'revenue' => 400]);

        $out = $this->data('measurement/compare?from=2026-10-01&to=2026-10-31');
        $this->assertSame(['platform_reported', 'store_confirmed', 'ga4_measured'], array_column($out['sources'], 'key'));
        $this->assertFalse($out['summed']);
        foreach ($out['sources'] as $s) {
            $this->assertNotSame('', $s['label_ar']);
            $this->assertArrayHasKey('orders', $s);
        }
        $row = collect($out['rows'])->keyBy('platform')['meta'];
        $this->assertEquals(10.0, $row['platform_reported']['orders']);
        $this->assertSame(1, $row['store_confirmed']['orders']);
        $this->assertEquals(4.0, $row['ga4_measured']['orders']);
        $this->assertEquals(9.0, $row['differences']['platform_minus_store_orders']);
        $this->assertTrue($row['differences']['material']);
        $this->assertNotSame('', $row['explanation']['ar']);
        $this->assertSame(1, $out['store_unattributed']['orders']);
        $this->assertEquals(40.0, $out['store_unattributed']['revenue']);
        $sources = collect($out['sources'])->keyBy('key');
        $this->assertSame(2, $sources['store_confirmed']['orders'], 'the store total is its own, not the sum of the columns');
    }

    public function test_a_source_with_no_data_says_so_in_the_comparison(): void
    {
        $this->metric('conversions', 5, 'meta');
        $out = $this->data('measurement/compare?from=2026-10-01&to=2026-10-31', $this->sibling);
        $this->assertSame(['platform_reported' => false, 'store_confirmed' => false, 'ga4_measured' => false], $out['available'], 'the sibling has none of the three, and another project\'s rows are not its data');
        $this->assertSame([], $out['rows']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    private function url(string $path, ?Project $project = null): string
    {
        return '/api/v1/projects/'.($project ?? $this->project)->id.'/'.$path;
    }

    /** @return array<string, mixed> */
    private function data(string $path, ?Project $project = null): array
    {
        return $this->actingAs($this->owner, 'sanctum')->getJson($this->url($path, $project))->assertOk()->json('data');
    }

    private function download(string $path): string
    {
        $response = $this->actingAs($this->owner, 'sanctum')->get($this->url($path))->assertOk();
        $this->assertInstanceOf(StreamedResponse::class, $response->baseResponse);

        return $response->streamedContent();
    }

    private function storeFor(Project $project, string $external): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'salla', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Salla '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'salla', 'account_type' => 'store', 'external_id' => $external, 'name' => 'Shop '.$external, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'salla', 'purpose' => 'store', 'is_active' => true,
        ]);

        return $account;
    }

    private function property(Project $project): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4');
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'external_id' => '111', 'name' => 'Prop', 'status' => 'active', 'discovered_at' => Carbon::now(), 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    /** @param array<string, float|int> $metrics */
    private function dimRow(ExternalAccount $property, Project $project, string $breakdown, string $d1, string $d2, array $metrics): void
    {
        DB::table('measurement_dimension_rows')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $property->id,
            'property_id' => '111', 'metric_date' => '2026-10-05', 'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2,
            'metrics' => json_encode($metrics), 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $over */
    private function order(string $reference, float $total, array $over = [], ?ExternalAccount $store = null, ?Project $project = null): CommerceOrder
    {
        return CommerceOrder::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => ($project ?? $this->project)->id, 'external_account_id' => ($store ?? $this->store)->id,
            'provider' => 'salla', 'external_id' => 'ext-'.$reference, 'reference' => $reference, 'status' => 'completed', 'placed_at' => '2026-10-05 10:00:00',
            'currency' => 'USD', 'total' => $total, ...$over,
        ]);
    }

    private function customer(string $external, ?string $firstSeen): CommerceCustomer
    {
        return CommerceCustomer::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id,
            'provider' => 'salla', 'external_id' => $external, 'first_seen_at' => $firstSeen,
        ]);
    }

    private function product(string $external, string $name, string $category): CommerceProduct
    {
        return CommerceProduct::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->store->id,
            'provider' => 'salla', 'external_id' => $external, 'name' => $name, 'category' => $category, 'status' => 'active',
        ]);
    }

    private function item(CommerceOrder $order, CommerceProduct $product, float $qty, float $total): void
    {
        CommerceOrderItem::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'commerce_order_id' => $order->id, 'commerce_product_id' => $product->id,
            'external_id' => 'i-'.Str::random(6), 'name' => $product->name, 'quantity' => $qty, 'total' => $total,
        ]);
    }

    private function metric(string $key, float $value, string $provider = 'meta'): void
    {
        $account = ExternalAccount::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'provider' => $provider, 'external_id' => 'ad-1', 'account_type' => 'ad_account'],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider_connection_id' => $this->store->provider_connection_id, 'name' => 'Ad', 'status' => 'active', 'discovered_at' => Carbon::now()],
        );
        ProjectIntegrationBinding::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id],
            ['client_workspace_id' => $this->project->client_workspace_id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true],
        );
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'external_campaign_id' => (string) Str::uuid(), 'provider' => $provider,
            'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
        ]);
    }
}
